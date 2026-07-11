<?php

declare(strict_types=1);

namespace App\Domain\Bot;

use App\Domain\Party\PartySystem;
use App\Domain\Support\Rng\RngInterface;

/**
 * Port src/systems/botSystem.ts. Generacja botów-towarzyszy (staty per klasa/
 * poziom z classes.json), akcje bojowe botów, oraz wybór celu aggro bossa.
 *
 * PARYTET RNG: funkcje losujące konsumują RngInterface w DOKŁADNIE tej samej
 * kolejności co TS `Math.random` (klasa → offset poziomu → nazwa), więc z tym
 * samym seedem mulberry32 dają identyczny wynik (golden-vectory z seedem).
 * Uwaga: `Math.floor(Math.random()*n)` portujemy jako `(int) floor(nextFloat()*n)`
 * — NIE przez nextInt(), bo nextInt ma short-circuit dla n==1 i NIE konsumowałby
 * RNG (TS by skonsumował) → rozjazd sekwencji.
 *
 * PARAMETRYZACJA id: TS `id = bot_${botIdCounter}_${Date.now()}` łączy licznik
 * modułu i zegar — oba to runtime-owe artefakty. Tu przyjmujemy je jawnie
 * ($botSeq, $nowMs) i odtwarzamy `bot_{seq}_{now}` 1:1 (reguła Date.now →
 * parametr; licznik = sekwencja przydzielana przez wołającego).
 *
 * AGGRO: `pickAggroTarget` w wariancie ważonym deleguje do
 * PartySystem::pickWeightedAggroTarget — tak jak TS botSystem importuje
 * pickWeightedAggroTarget z partySystem (jedno źródło wag klas).
 *
 * NIEPORTOWANE (świadomie): BOT_CLASS_ICONS + getBotLogIcon — shortcody ikon do
 * combat-logu, czysta prezentacja UI.
 */
final class BotSystem
{
    /** @var list<string> Kolejność klas (jak TS ALL_CLASSES) — istotna dla indeksu RNG. */
    public const ALL_CLASSES = ['Knight', 'Mage', 'Cleric', 'Archer', 'Rogue', 'Necromancer', 'Bard'];

    /** Staty bota = 80% tego, co miałby gracz tej klasy/poziomu. */
    private const BOT_STAT_MULTIPLIER = 0.8;

    /** @var list<string> Klasy z magicLevel = floor(level*0.3). */
    private const MAGIC_CLASSES = ['Mage', 'Cleric', 'Necromancer'];

    /** @var array<string, list<string>> Pule nazw per klasa (kopiowane 1:1 z botSystem.ts). */
    public const BOT_NAMES = [
        'Knight' => ['Sir Aldric', 'Sir Gavain', 'Dame Irina', 'Sir Borin', 'Dame Elsa', 'Sir Tormund', 'Dame Kira'],
        'Mage' => ['Mystic Elara', 'Archmage Zed', 'Sorceress Lyra', 'Magus Kael', 'Enchanter Nyx', 'Sage Orin', 'Witch Mira'],
        'Cleric' => ['Brother Amon', 'Sister Celeste', 'Father Egan', 'Priestess Nara', 'Deacon Piers', 'Mother Thea', 'Abbot Lucius'],
        'Archer' => ['Sharp Finn', 'Ranger Kael', 'Huntress Lyssa', 'Bowman Rex', 'Scout Mara', 'Tracker Dain', 'Sniper Vela'],
        'Rogue' => ['Shadow Vex', 'Blade Nyx', 'Shade Kira', 'Phantom Rael', 'Whisper Thorn', 'Ghost Sable', 'Dusk Zara'],
        'Necromancer' => ['Darkis Vol', 'Witch Morrigan', 'Cursed Theron', 'Deathcaller Ula', 'Bonelord Sev', 'Plaguebringer Ash', 'Gravemind Ossa'],
        'Bard' => ['Melody Aria', 'Minstrel Jay', 'Troubadour Lute', 'Songbird Faye', 'Rhymer Cal', 'Harmony Sage', 'Balladeer Tine'],
    ];

    /** @var array<string, string> id klasy TS → klucz w skills.json activeSkills. */
    private const CLASS_KEY_MAP = [
        'Knight' => 'knight', 'Mage' => 'mage', 'Cleric' => 'cleric', 'Archer' => 'archer',
        'Rogue' => 'rogue', 'Necromancer' => 'necromancer', 'Bard' => 'bard',
    ];

    /** @var array<string, array<string, mixed>> Dane klasy z classes.json, keyed by id. */
    private array $classData;

    /** @var array<string, array{id:string, name_pl:string, damage:int|float, mpCost:int|float, cooldown:int|float}|null> */
    private array $firstSkills;

    /**
     * @param  list<array<string, mixed>>  $classes  pełna zawartość classes.json
     * @param  array<string, mixed>  $skills  pełna zawartość skills.json
     */
    public function __construct(array $classes, array $skills)
    {
        $this->classData = self::buildClassData($classes);
        $this->firstSkills = self::buildFirstSkills($skills);
    }

    /**
     * @param  list<array<string, mixed>>  $classes
     * @return array<string, array<string, mixed>>
     */
    private static function buildClassData(array $classes): array
    {
        $map = [];
        foreach ($classes as $cls) {
            $map[(string) $cls['id']] = $cls;
        }

        return $map;
    }

    /**
     * @param  array<string, mixed>  $skills
     * @return array<string, array{id:string, name_pl:string, damage:int|float, mpCost:int|float, cooldown:int|float}|null>
     */
    private static function buildFirstSkills(array $skills): array
    {
        /** @var array<string, list<array<string, mixed>>> $active */
        $active = $skills['activeSkills'] ?? [];
        $result = [];
        foreach (self::ALL_CLASSES as $cls) {
            $list = $active[self::CLASS_KEY_MAP[$cls]] ?? null;
            if (is_array($list) && count($list) > 0) {
                $first = $list[0];
                $result[$cls] = [
                    'id' => (string) $first['id'],
                    'name_pl' => (string) $first['name_pl'],
                    'damage' => $first['damage'],
                    'mpCost' => $first['mpCost'],
                    'cooldown' => $first['cooldown'],
                ];
            } else {
                $result[$cls] = null;
            }
        }

        return $result;
    }

    // ---- Staty bota (czyste, golden bit-exact) ------------------------------

    /**
     * @return array{hp:int, mp:int, attack:int, defense:int, speed:int|float, magicLevel:int}
     */
    public function calculateBotStats(int $level, string $cls): array
    {
        $data = $this->classData[$cls] ?? null;
        if ($data === null) {
            return ['hp' => 100, 'mp' => 50, 'attack' => 10, 'defense' => 5, 'speed' => 1, 'magicLevel' => 0];
        }

        $base = $data['baseStats'];
        $hp = (int) floor(($base['hp'] + $data['hpPerLevel'] * $level) * self::BOT_STAT_MULTIPLIER);
        $mp = (int) floor(($base['mp'] + $data['mpPerLevel'] * $level) * self::BOT_STAT_MULTIPLIER);
        $attack = (int) floor(($base['attack'] + $data['attackPerLevel'] * $level) * self::BOT_STAT_MULTIPLIER);
        $defense = (int) floor(($base['defense'] + $data['defensePerLevel'] * $level) * self::BOT_STAT_MULTIPLIER);
        $speed = $base['speed'];
        $magicLevel = in_array($cls, self::MAGIC_CLASSES, true) ? (int) floor($level * 0.3) : 0;

        return ['hp' => $hp, 'mp' => $mp, 'attack' => $attack, 'defense' => $defense, 'speed' => $speed, 'magicLevel' => $magicLevel];
    }

    // ---- Generacja botów (RNG w stałej kolejności) --------------------------

    /**
     * @param  list<string>  $existingClasses
     * @return array<string, mixed> IBot
     */
    public function generateBot(
        RngInterface $rng,
        int $playerLevel,
        string $playerClass,
        array $existingClasses,
        int $botSeq,
        int $nowMs,
    ): array {
        // Losowa klasa różna od gracza i istniejących botów (1 konsumpcja RNG).
        $excluded = array_merge([$playerClass], $existingClasses);
        $available = array_values(array_filter(
            self::ALL_CLASSES,
            static fn (string $c): bool => ! in_array($c, $excluded, true),
        ));
        if (count($available) > 0) {
            $botClass = $available[(int) floor($rng->nextFloat() * count($available))];
        } else {
            $botClass = self::ALL_CLASSES[(int) floor($rng->nextFloat() * count(self::ALL_CLASSES))];
        }

        // Poziom = poziom gracza +/- 2 (1 konsumpcja RNG).
        $levelOffset = (int) floor($rng->nextFloat() * 5) - 2;
        $botLevel = max(1, $playerLevel + $levelOffset);

        $stats = $this->calculateBotStats($botLevel, $botClass);

        // Losowa nazwa dla klasy (1 konsumpcja RNG).
        $names = self::BOT_NAMES[$botClass];
        $name = $names[(int) floor($rng->nextFloat() * count($names))];

        return $this->buildBot($botSeq, $nowMs, $name, $botClass, $botLevel, $stats);
    }

    /**
     * Bot o jawnie wskazanej klasie (gracz wybiera klasę towarzysza).
     *
     * @return array<string, mixed> IBot
     */
    public function generateBotWithClass(
        RngInterface $rng,
        int $playerLevel,
        string $botClass,
        int $botSeq,
        int $nowMs,
    ): array {
        $levelOffset = (int) floor($rng->nextFloat() * 5) - 2;
        $botLevel = max(1, $playerLevel + $levelOffset);
        $stats = $this->calculateBotStats($botLevel, $botClass);
        $names = self::BOT_NAMES[$botClass];
        $name = $names[(int) floor($rng->nextFloat() * count($names))];

        return $this->buildBot($botSeq, $nowMs, $name, $botClass, $botLevel, $stats);
    }

    /**
     * Pełna drużyna botów. Każdy kolejny bot wyklucza klasy już wybrane.
     * Kolejne id = $startSeq, $startSeq+1, ... (jak inkrementacja botIdCounter).
     *
     * @return list<array<string, mixed>>
     */
    public function generateBotParty(
        RngInterface $rng,
        int $playerLevel,
        string $playerClass,
        int $count,
        int $startSeq,
        int $nowMs,
    ): array {
        $bots = [];
        $usedClasses = [];
        for ($i = 0; $i < $count; $i++) {
            $bot = $this->generateBot($rng, $playerLevel, $playerClass, $usedClasses, $startSeq + $i, $nowMs);
            $usedClasses[] = (string) $bot['class'];
            $bots[] = $bot;
        }

        return $bots;
    }

    /**
     * @param  array{hp:int, mp:int, attack:int, defense:int, speed:int|float, magicLevel:int}  $stats
     * @return array<string, mixed> IBot
     */
    private function buildBot(int $botSeq, int $nowMs, string $name, string $class, int $level, array $stats): array
    {
        $skill = $this->firstSkills[$class] ?? null;

        return [
            'id' => "bot_{$botSeq}_{$nowMs}",
            'name' => $name,
            'class' => $class,
            'level' => $level,
            'hp' => $stats['hp'],
            'maxHp' => $stats['hp'],
            'mp' => $stats['mp'],
            'maxMp' => $stats['mp'],
            'attack' => $stats['attack'],
            'defense' => $stats['defense'],
            'attackSpeed' => $stats['speed'],
            'critChance' => 5,
            'magicLevel' => $stats['magicLevel'],
            'skillId' => $skill['id'] ?? null,
            'skillDamageMultiplier' => $skill['damage'] ?? 0,
            'skillMpCost' => $skill['mpCost'] ?? 0,
            'skillCooldownMs' => $skill['cooldown'] ?? 5000,
            'alive' => true,
        ];
    }

    // ---- Akcja bojowa bota (RNG: 1 rzut na variance, zawsze) ----------------

    /**
     * @param  array<string, mixed>  $bot  IBot
     * @return array{botId:string, botName:string, type:string, damage:int, skillName?:string}
     */
    public function calculateBotAction(RngInterface $rng, array $bot, int|float $bossDefense, bool $canUseSkill): array
    {
        $baseDmg = max(1, $bot['attack'] - $bossDefense);
        $variance = (int) floor($baseDmg * 0.2);
        // Rzut na wariancję liczony ZAWSZE przed gałęzią (jak TS) — 1 konsumpcja RNG.
        $finalBaseDmg = max(1, $baseDmg - $variance + (int) floor($rng->nextFloat() * ($variance * 2 + 1)));

        if ($canUseSkill && $bot['skillId'] && $bot['mp'] >= $bot['skillMpCost'] && $bot['skillDamageMultiplier'] > 0) {
            $skillDmg = max(1, (int) floor($bot['attack'] * $bot['skillDamageMultiplier'] * 0.15));
            $skillInfo = $this->firstSkills[$bot['class']] ?? null;

            return [
                'botId' => $bot['id'],
                'botName' => $bot['name'],
                'type' => 'skill',
                'damage' => $skillDmg,
                'skillName' => $skillInfo['name_pl'] ?? $bot['skillId'],
            ];
        }

        return [
            'botId' => $bot['id'],
            'botName' => $bot['name'],
            'type' => 'attack',
            'damage' => $finalBaseDmg,
        ];
    }

    // ---- Wybór celu aggro bossa ---------------------------------------------

    /**
     * Wybiera następny cel aggro bossa.
     *  - Legacy: list<string> aliveBotIds → losowo jednostajnie po ['player', ...ids].
     *  - Nowy:   list<array{id,class}> → wybór ważony klasą (PartySystem::pickWeightedAggroTarget).
     *
     * @param  list<string>|list<array{id:string, class:string}>  $arg
     */
    public static function pickAggroTarget(RngInterface $rng, array $arg): string
    {
        if (count($arg) === 0) {
            return 'player';
        }

        if (is_string($arg[0])) {
            /** @var list<string> $arg */
            $targets = array_merge(['player'], $arg);

            return $targets[(int) floor($rng->nextFloat() * count($targets))];
        }

        /** @var list<array{id:string, class:string}> $arg */
        return PartySystem::pickWeightedAggroTarget($rng, $arg) ?? 'player';
    }

    // ---- Obrażenia AOE (co 5-ty atak bossa, 50%) — czyste --------------------

    public static function calculateAoeDamage(int|float $bossAttack, int|float $targetDefense): int
    {
        $baseDmg = max(1, $bossAttack - $targetDefense);

        return max(1, (int) floor($baseDmg * 0.5));
    }

    /** Czy tura bossa jest turą AOE (co 5-ta). */
    public static function isBossAoeTurn(int $turnCounter): bool
    {
        return $turnCounter > 0 && $turnCounter % 5 === 0;
    }

    /** Interwał przełączenia aggro (3-5 tur) — 1 konsumpcja RNG. */
    public static function getAggroSwitchInterval(RngInterface $rng): int
    {
        return 3 + (int) floor($rng->nextFloat() * 3);
    }
}
