<?php

declare(strict_types=1);

namespace App\Domain\Bot;

use App\Domain\Party\PartySystem;
use App\Domain\Support\Rng\RngInterface;

final class BotSystem
{
    public const ALL_CLASSES = ['Knight', 'Mage', 'Cleric', 'Archer', 'Rogue', 'Necromancer', 'Bard'];

    private const BOT_STAT_MULTIPLIER = 0.8;

    private const MAGIC_CLASSES = ['Mage', 'Cleric', 'Necromancer'];

    private const DEF_K = 1.0;

    private const DEF_CAP = 0.75;

    private const DEF_BASE = 25;

    private const DMG_COMPRESS_K = 0.48;

    private const DMG_COMPRESS_P = 0.80;

    private static function compressPlayerDamage(int|float $mitigatedDamage): float
    {
        return self::DMG_COMPRESS_K * ($mitigatedDamage <= 0 ? 0.0 : ($mitigatedDamage ** self::DMG_COMPRESS_P));
    }

    public const BOT_NAMES = [
        'Knight' => ['Sir Aldric', 'Sir Gavain', 'Dame Irina', 'Sir Borin', 'Dame Elsa', 'Sir Tormund', 'Dame Kira'],
        'Mage' => ['Mystic Elara', 'Archmage Zed', 'Sorceress Lyra', 'Magus Kael', 'Enchanter Nyx', 'Sage Orin', 'Witch Mira'],
        'Cleric' => ['Brother Amon', 'Sister Celeste', 'Father Egan', 'Priestess Nara', 'Deacon Piers', 'Mother Thea', 'Abbot Lucius'],
        'Archer' => ['Sharp Finn', 'Ranger Kael', 'Huntress Lyssa', 'Bowman Rex', 'Scout Mara', 'Tracker Dain', 'Sniper Vela'],
        'Rogue' => ['Shadow Vex', 'Blade Nyx', 'Shade Kira', 'Phantom Rael', 'Whisper Thorn', 'Ghost Sable', 'Dusk Zara'],
        'Necromancer' => ['Darkis Vol', 'Witch Morrigan', 'Cursed Theron', 'Deathcaller Ula', 'Bonelord Sev', 'Plaguebringer Ash', 'Gravemind Ossa'],
        'Bard' => ['Melody Aria', 'Minstrel Jay', 'Troubadour Lute', 'Songbird Faye', 'Rhymer Cal', 'Harmony Sage', 'Balladeer Tine'],
    ];

    private const CLASS_KEY_MAP = [
        'Knight' => 'knight', 'Mage' => 'mage', 'Cleric' => 'cleric', 'Archer' => 'archer',
        'Rogue' => 'rogue', 'Necromancer' => 'necromancer', 'Bard' => 'bard',
    ];

    private array $classData;

    private array $firstSkills;

    public function __construct(array $classes, array $skills)
    {
        $this->classData = self::buildClassData($classes);
        $this->firstSkills = self::buildFirstSkills($skills);
    }

    private static function buildClassData(array $classes): array
    {
        $map = [];
        foreach ($classes as $cls) {
            $map[(string) $cls['id']] = $cls;
        }

        return $map;
    }

    private static function buildFirstSkills(array $skills): array
    {
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

    public function generateBot(
        RngInterface $rng,
        int $playerLevel,
        string $playerClass,
        array $existingClasses,
        int $botSeq,
        int $nowMs,
    ): array {
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

        $levelOffset = (int) floor($rng->nextFloat() * 5) - 2;
        $botLevel = max(1, $playerLevel + $levelOffset);

        $stats = $this->calculateBotStats($botLevel, $botClass);

        $names = self::BOT_NAMES[$botClass];
        $name = $names[(int) floor($rng->nextFloat() * count($names))];

        return $this->buildBot($botSeq, $nowMs, $name, $botClass, $botLevel, $stats);
    }

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

    public function calculateBotAction(RngInterface $rng, array $bot, int|float $bossDefense, bool $canUseSkill): array
    {
        $baseDmg = self::mitigateDamage($bot['attack'], $bossDefense, $bot['level'], true);
        $variance = (int) floor($baseDmg * 0.2);
        $finalBaseDmg = max(1, $baseDmg - $variance + (int) floor($rng->nextFloat() * ($variance * 2 + 1)));

        if ($canUseSkill && $bot['skillId'] && $bot['mp'] >= $bot['skillMpCost'] && $bot['skillDamageMultiplier'] > 0) {
            $skillDmg = max(1, (int) floor(self::compressPlayerDamage($bot['attack'] * $bot['skillDamageMultiplier'] * 0.15)));
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

    public static function pickAggroTarget(RngInterface $rng, array $arg): string
    {
        if (count($arg) === 0) {
            return 'player';
        }

        if (is_string($arg[0])) {
            $targets = array_merge(['player'], $arg);

            return $targets[(int) floor($rng->nextFloat() * count($targets))];
        }

        return PartySystem::pickWeightedAggroTarget($rng, $arg) ?? 'player';
    }

    public static function calculateAoeDamage(int|float $bossAttack, int|float $targetDefense, int|float $bossLevel = 1): int
    {
        $baseDmg = self::mitigateDamage($bossAttack, $targetDefense, $bossLevel);

        return (int) max(1, floor($baseDmg * 0.5));
    }

    private static function safeN(int|float|null $value, float $fallback = 0.0): float
    {
        $n = (float) ($value ?? $fallback);

        return is_finite($n) ? $n : $fallback;
    }

    private static function defMitigation(int|float $enemyDef, int|float $attackerLevel): float
    {
        $def = max(0.0, self::safeN($enemyDef));
        $lvl = max(1.0, self::safeN($attackerLevel, 1.0));
        if ($def <= 0.0) {
            return 0.0;
        }

        return min(self::DEF_CAP, $def / ($def + self::DEF_K * $lvl + self::DEF_BASE));
    }

    private static function mitigateDamage(int|float $rawDamage, int|float $enemyDef, int|float $attackerLevel, bool $playerSource = false): int
    {
        $m = self::safeN($rawDamage) * (1 - self::defMitigation($enemyDef, $attackerLevel));

        return (int) max(1, floor($playerSource ? self::compressPlayerDamage($m) : $m));
    }

    public static function isBossAoeTurn(int $turnCounter): bool
    {
        return $turnCounter > 0 && $turnCounter % 5 === 0;
    }

    public static function getAggroSwitchInterval(RngInterface $rng): int
    {
        return 3 + (int) floor($rng->nextFloat() * 3);
    }
}
