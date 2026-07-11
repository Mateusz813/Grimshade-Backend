<?php

declare(strict_types=1);

namespace App\Domain\Raid;

use App\Domain\Support\Rng\RngInterface;

/**
 * Port src/systems/raidSystem.ts. Formuły raidów: liczba fal, lista raidów
 * (jeden per dungeon), skalowanie statów bossów, estymata nagród i nagroda
 * XP/gold per członek. Treść (dungeons/monsters) wstrzykiwana przez konstruktor
 * z ContentRepository — to samo ŹRÓDŁO PRAWDY balansu co front (src/data).
 *
 * PARYTET (golden-vectory w tests/Golden/fixtures/raidSystem.json):
 *  - Deterministyczne bit-exact: getRaidWaveCount, getAllRaids, getRaidById,
 *    estimateRaidRewards, generateWaveBosses (staty), computeMemberRewards
 *    (XP+gold z rollMemberRewards liczone PRZED jakimkolwiek RNG).
 *  - Selektory rzadkości dropów (selectItemRarity / selectStoneDrop /
 *    selectCompletionRarity): czyste funkcje wartości losowej → rzadkość;
 *    dowiedzione wektorami roll→rzadkość oraz seedami mulberry32 (float z seeda
 *    → rzadkość) — te same progi kumulatywne i operator `<` co TS.
 *
 * ŚWIADOMIE NIEPORTOWALNE (brak bit-parity — udokumentowane):
 *  - Pełne losowanie dropów w TS rollMemberRewards woła generateRandomItem
 *    (itemGenerator), które używa `[...pool].sort(() => Math.random() - 0.5)` —
 *    sort z losowym komparatorem konsumuje ZMIENNĄ liczbę Math.random w V8 vs
 *    PHP, więc reszta strumienia RNG (chest/potion/stone/completion) się
 *    rozjeżdża. Dlatego generacja itemów jest SERWER-AUTORYTATYWNA: backend
 *    rolluje własnym RNG (rollMemberDrops niżej — deskryptory dropów przez
 *    RngInterface, testowane własnościowo), a nie odtwarza sekwencji TS bajt
 *    w bajt. Sam obiekt itemu produkuje osobny ItemGenerator (poza tym systemem);
 *    potiony rozwiązuje LootSystem::rollPotionDrop (żeby nie duplikować tierów).
 *  - `id` bossa (`raid_boss_..._${Date.now()}_${Math.random().toString(36)}`) =
 *    niedeterministyczny token instancji, bez wartości logicznej → pominięty.
 *  - todayIso() (new Date), etykiety UI dropów, typy zdarzeń Realtime → pominięte.
 */
final class RaidSystem
{
    /**
     * Mnożnik nagrody end-to-end na wierzchu per-kill boss-tier (×12 wg spec).
     */
    public const RAID_REWARD_MULTIPLIER = 12;

    /** Szansa Skrzyni Zaklęć per poziom skrzyni (0.25%). */
    public const SPELL_CHEST_CHANCE_PER_LEVEL = 0.0025;

    // Boss-tier mnożniki statów (MONSTER_STAT_MULTIPLIERS.boss z combat.ts).
    private const BOSS_HP = 10.0;

    private const BOSS_ATK = 2.5;

    private const BOSS_DEF = 2.0;

    private const BOSS_XP = 10.0;

    private const BOSS_GOLD = 15.0;

    /**
     * Rzut rzadkości itemu per boss (sumuje do 100%). MIRROR ITEM_RARITY_CHANCES
     * z raidSystem.ts — kolejność ma znaczenie (kumulatywnie).
     *
     * @var list<array{0:string, 1:float}>
     */
    public const ITEM_RARITY_CHANCES = [
        ['heroic', 0.005],
        ['mythic', 0.05],
        ['legendary', 0.10],
        ['epic', 0.20],
        ['rare', 0.50],
        ['common', 0.145],
    ];

    /**
     * Rzut rzadkości kamienia ulepszeń per boss (sumuje do 100%). MIRROR
     * STONE_DROPS z raidSystem.ts.
     *
     * @var list<array{0:string, 1:float, 2:string}>
     */
    public const STONE_DROPS = [
        ['heroic', 0.01, 'heroic_stone'],
        ['mythic', 0.15, 'mythic_stone'],
        ['legendary', 0.25, 'legendary_stone'],
        ['epic', 0.40, 'epic_stone'],
        ['rare', 0.10, 'rare_stone'],
        ['common', 0.09, 'common_stone'],
    ];

    /**
     * Rzut bonusowy za ukończenie rajdu (sumuje do 100%; skewed wyżej niż
     * per-boss). MIRROR COMPLETION_ROLL z raidSystem.ts.
     *
     * @var list<array{0:string, 1:float}>
     */
    public const COMPLETION_ROLL = [
        ['heroic', 0.015],
        ['mythic', 0.08],
        ['legendary', 0.15],
        ['epic', 0.25],
        ['rare', 0.40],
        ['common', 0.105],
    ];

    /**
     * Poziomy Skrzyń Zaklęć (SPELL_CHEST_LEVELS z skillSystem.ts). Skrzynia
     * dropuje tylko dla poziomów ≤ poziom raidu.
     *
     * @var list<int>
     */
    public const SPELL_CHEST_LEVELS = [5, 10, 20, 30, 40, 50, 60, 70, 80, 100, 150, 300, 600, 800, 1000];

    /** @var list<array{id:string, name_pl:string, level:int}> */
    private array $dungeons;

    /** @var list<array{id:string, name_pl:string, level:int, hp:int, attack:int, defense:int, xp:int|float, gold:array{0:int, 1:int}, sprite:string}> */
    private array $monsters;

    /**
     * @param  list<array<string, mixed>>  $dungeons  pełna lista z dungeons.json
     * @param  list<array<string, mixed>>  $monsters  pełna lista z monsters.json
     */
    public function __construct(array $dungeons, array $monsters)
    {
        $this->dungeons = array_values($dungeons);
        $this->monsters = array_values($monsters);
    }

    /** Skaluj liczbę fal z poziomem raidu — 1 fala na lvl 1, do 5 na 1000. */
    public static function getRaidWaveCount(int $raidLevel): int
    {
        if ($raidLevel <= 10) {
            return 1;
        }
        if ($raidLevel <= 50) {
            return 2;
        }
        if ($raidLevel <= 200) {
            return 3;
        }
        if ($raidLevel <= 500) {
            return 4;
        }

        return 5;
    }

    /**
     * Jeden raid per dungeon.
     *
     * @return list<array{id:string, name_pl:string, level:int, waves:int, dailyAttempts:int, sourceDungeonId:string}>
     */
    public function getAllRaids(): array
    {
        $raids = [];
        foreach ($this->dungeons as $d) {
            $level = (int) $d['level'];
            $raids[] = [
                'id' => 'raid_'.self::stripDungeonPrefix((string) $d['id']),
                'name_pl' => $d['name_pl'],
                'level' => $level,
                'waves' => self::getRaidWaveCount($level),
                // 2026-04 spec: 5 dziennych prób (party-only, więcej koordynacji).
                'dailyAttempts' => 5,
                'sourceDungeonId' => $d['id'],
            ];
        }

        return $raids;
    }

    /**
     * @return array{id:string, name_pl:string, level:int, waves:int, dailyAttempts:int, sourceDungeonId:string}|null
     */
    public function getRaidById(string $id): ?array
    {
        foreach ($this->getAllRaids() as $raid) {
            if ($raid['id'] === $id) {
                return $raid;
            }
        }

        return null;
    }

    /**
     * Estymata nagród na kartę raidu — mirror rollMemberRewards przy pełnym
     * clearze (bossesDefeated === waves × 4).
     *
     * @param  array{level:int|string, waves:int|string}  $raid
     * @return array{goldMin:int, goldMax:int, xp:int}
     */
    public function estimateRaidRewards(array $raid): array
    {
        $level = (int) $raid['level'];
        $base = $this->pickBaseRaidMonster($level);
        $totalBosses = (int) $raid['waves'] * 4;
        $factor = $totalBosses * self::RAID_REWARD_MULTIPLIER;
        $xpPerKill = (int) floor($base['xp'] * self::BOSS_XP);
        $goldMinPerKill = (int) floor($base['gold'][0] * self::BOSS_GOLD);
        $goldMaxPerKill = (int) floor($base['gold'][1] * self::BOSS_GOLD);
        $xpBonus = self::levelXpBonus($level);
        $goldBonus = self::levelGoldBonus($level);

        return [
            'goldMin' => $goldMinPerKill * $factor + $goldBonus,
            'goldMax' => $goldMaxPerKill * $factor + $goldBonus,
            'xp' => $xpPerKill * $factor + $xpBonus,
        ];
    }

    /**
     * 4 sloty boss-tier na falę. Staty = picked monster × boss-hat, skalowane
     * różnicą poziomów (+5% per level gap) i indeksem fali (+15% per fala).
     *
     * `id` bossa (niedeterministyczny token instancji z TS) świadomie pominięty.
     *
     * @param  array{level:int|string}  $raid
     * @return list<array{baseId:string, level:int, name:string, sprite:string, maxHp:int, currentHp:int, attack:int, defense:int, isDead:bool, waveIdx:int, slotIdx:int}>
     */
    public function generateWaveBosses(array $raid, int $waveIdx): array
    {
        $level = (int) $raid['level'];
        $base = $this->pickBaseRaidMonster($level);
        $levelGap = max(1, $level - (int) $base['level']);
        // Mnożnik: +5% per level gap, +15% per indeks fali (późniejsze trudniejsze).
        $mult = (1 + $levelGap * 0.05) * (1 + $waveIdx * 0.15);

        $bosses = [];
        for ($slotIdx = 0; $slotIdx < 4; $slotIdx++) {
            $hp = (int) floor($base['hp'] * self::BOSS_HP * $mult);
            $bosses[] = [
                'baseId' => $base['id'],
                'level' => (int) $base['level'],
                'name' => $base['name_pl'].' #'.($slotIdx + 1),
                'sprite' => $base['sprite'],
                'maxHp' => $hp,
                'currentHp' => $hp,
                'attack' => (int) floor($base['attack'] * self::BOSS_ATK * $mult),
                'defense' => (int) floor($base['defense'] * self::BOSS_DEF * $mult),
                'isDead' => false,
                'waveIdx' => $waveIdx,
                'slotIdx' => $slotIdx,
            ];
        }

        return $bosses;
    }

    /**
     * Deterministyczna część rollMemberRewards: XP i gold z (raid, bossesDefeated).
     * Per-kill = boss-tier mob payout (xp × 10, gold-mid × 15) × ×12; bonus za
     * level tylko przy pełnym clearze (bossesDefeated ≥ waves × 4).
     *
     * @param  array{level:int|string, waves:int|string}  $raid
     * @return array{xp:int, gold:int}
     */
    public function computeMemberRewards(array $raid, int $bossesDefeated): array
    {
        $level = (int) $raid['level'];
        $base = $this->pickBaseRaidMonster($level);
        $xpPerKill = (int) floor($base['xp'] * self::BOSS_XP);
        $goldMidPerKill = (int) floor((($base['gold'][0] + $base['gold'][1]) / 2) * self::BOSS_GOLD);
        $totalSlots = max(1, (int) $raid['waves'] * 4);
        $cleared = $bossesDefeated >= $totalSlots;
        $xpBonus = $cleared ? self::levelXpBonus($level) : 0;
        $goldBonus = $cleared ? self::levelGoldBonus($level) : 0;

        return [
            'xp' => $xpPerKill * $bossesDefeated * self::RAID_REWARD_MULTIPLIER + $xpBonus,
            'gold' => $goldMidPerKill * $bossesDefeated * self::RAID_REWARD_MULTIPLIER + $goldBonus,
        ];
    }

    /**
     * Rzut rzadkości itemu z wartości losowej [0,1) — kumulatywnie po
     * ITEM_RARITY_CHANCES. Zwraca null gdy żaden próg nietrafiony (jak TS: brak
     * pushu itemu).
     */
    public static function selectItemRarity(float $roll): ?string
    {
        $cum = 0.0;
        foreach (self::ITEM_RARITY_CHANCES as [$rarity, $chance]) {
            $cum += $chance;
            if ($roll < $cum) {
                return $rarity;
            }
        }

        return null;
    }

    /**
     * Rzut kamienia z wartości losowej — kumulatywnie po STONE_DROPS. null gdy
     * nietrafiony (jak TS: brak pushu kamienia).
     *
     * @return array{rarity:string, id:string}|null
     */
    public static function selectStoneDrop(float $roll): ?array
    {
        $cum = 0.0;
        foreach (self::STONE_DROPS as [$rarity, $chance, $id]) {
            $cum += $chance;
            if ($roll < $cum) {
                return ['rarity' => $rarity, 'id' => $id];
            }
        }

        return null;
    }

    /**
     * Rzut bonusu za ukończenie — kumulatywnie po COMPLETION_ROLL. Domyślnie
     * 'common' (jak TS: rolledRarity inicjalizowane na 'common').
     */
    public static function selectCompletionRarity(float $roll): string
    {
        $cum = 0.0;
        foreach (self::COMPLETION_ROLL as [$rarity, $chance]) {
            $cum += $chance;
            if ($roll < $cum) {
                return $rarity;
            }
        }

        return 'common';
    }

    /**
     * SERWER-AUTORYTATYWNE losowanie deskryptorów dropów (rzadkości + id), przez
     * RngInterface. NIE jest bit-parity z TS rollMemberRewards — TS przeplata
     * generateRandomItem (sort-shuffle) między rzutami, co rozjeżdża strumień
     * RNG (patrz docblock klasy). Kolejność rzutów tu jest ustalona i czysta:
     * per boss → item / skrzynie / kamień; na końcu → bonus za ukończenie.
     * Sam obiekt itemu (ItemGenerator) oraz potiony (LootSystem) poza zakresem.
     *
     * @param  array{level:int|string}  $raid
     * @return list<array{kind:string, rarity?:string, itemId?:string, amount?:int, isBonus?:bool}>
     */
    public function rollMemberDrops(RngInterface $rng, array $raid, int $bossesDefeated): array
    {
        $raidLevel = (int) $raid['level'];
        $eligibleChests = array_values(array_filter(
            self::SPELL_CHEST_LEVELS,
            static fn (int $lvl): bool => $lvl <= $raidLevel,
        ));

        $lines = [];
        for ($i = 0; $i < $bossesDefeated; $i++) {
            $itemRarity = self::selectItemRarity($rng->nextFloat());
            if ($itemRarity !== null) {
                $lines[] = ['kind' => 'item', 'rarity' => $itemRarity];
            }

            foreach ($eligibleChests as $chestLvl) {
                if ($rng->nextFloat() < self::SPELL_CHEST_CHANCE_PER_LEVEL) {
                    $lines[] = ['kind' => 'spell_chest', 'amount' => $chestLvl];
                }
            }

            $stone = self::selectStoneDrop($rng->nextFloat());
            if ($stone !== null) {
                $lines[] = ['kind' => 'upgrade_stone', 'rarity' => $stone['rarity'], 'itemId' => $stone['id']];
            }
        }

        // Bonus za ukończenie — gwarantowany, jeden na członka.
        $completion = self::selectCompletionRarity($rng->nextFloat());
        $lines[] = ['kind' => 'item', 'rarity' => $completion, 'isBonus' => true];

        return $lines;
    }

    /** bonus_xp = raidLevel² (różnicuje lvl 960 vs 980 poza mnożnikiem). */
    private static function levelXpBonus(int $raidLevel): int
    {
        return $raidLevel * $raidLevel;
    }

    /** bonus_gold = raidLevel × 1000. */
    private static function levelGoldBonus(int $raidLevel): int
    {
        return $raidLevel * 1_000;
    }

    /**
     * Monster-baza blisko poziomu raidu (≤). Odpowiednik TS: filter(level ≤ raid)
     * → stabilny sort malejąco po level → [0]. Bez ties w danych, ale robimy
     * bezpiecznie: najwyższy level ≤ raid, przy remisie NAJWCZEŚNIEJSZY w pliku
     * (strictly-greater podmienia — jak stabilny sort JS). Brak eligible → [0].
     *
     * @return array{id:string, name_pl:string, level:int, hp:int, attack:int, defense:int, xp:int|float, gold:array{0:int, 1:int}, sprite:string}
     */
    private function pickBaseRaidMonster(int $raidLevel): array
    {
        $best = null;
        foreach ($this->monsters as $m) {
            if ((int) $m['level'] <= $raidLevel && ($best === null || (int) $m['level'] > (int) $best['level'])) {
                $best = $m;
            }
        }

        /** @var array{id:string, name_pl:string, level:int, hp:int, attack:int, defense:int, xp:int|float, gold:array{0:int, 1:int}, sprite:string} */
        return $best ?? $this->monsters[0];
    }

    /** JS `.replace('dungeon_', '')` — usuwa PIERWSZE wystąpienie. */
    private static function stripDungeonPrefix(string $id): string
    {
        return preg_replace('/dungeon_/', '', $id, 1) ?? $id;
    }
}
