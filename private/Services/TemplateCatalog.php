<?php

namespace Pterodactyl\BlueprintFramework\Extensions\{identifier}\Services;

/**
 * Quick-start templates (spec section 16): selecting one pre-fills the
 * prompt; the user can still customize it before planning.
 */
final class TemplateCatalog
{
    /** @return array<string, array{label: string, icon: string, prompt: string}> */
    public static function all(): array
    {
        return [
            'lifestyle-survival' => [
                'label' => 'Lifestyle Survival',
                'icon' => 'tree',
                'prompt' => 'Build a relaxed lifestyle survival server focused on community, economy, jobs, player shops, land claims and quests with a friendly overworld theme.',
            ],
            'vanilla-plus' => [
                'label' => 'Vanilla+',
                'icon' => 'cube',
                'prompt' => 'Build a Vanilla+ survival server: near-vanilla gameplay with a few quality-of-life plugins for homes, teleport requests and anti-grief protection.',
            ],
            'economy-smp' => [
                'label' => 'Economy SMP',
                'icon' => 'coin',
                'prompt' => 'Build an economy-focused SMP for 30 players with player shops, jobs, a balanced currency, auction house and server-wide events.',
            ],
            'lifesteal' => [
                'label' => 'Lifesteal SMP',
                'icon' => 'heart',
                'prompt' => 'Build a Lifesteal SMP: players steal hearts on kills, hard difficulty, PvP enabled, no land claims, hearts crafting recipes and a kill leaderboard.',
            ],
            'hardcore' => [
                'label' => 'Hardcore Survival',
                'icon' => 'skull',
                'prompt' => 'Build a hardcore survival server: hard difficulty, one life rules enforced by plugins, banned flight, strict anti-cheat and a death ban system.',
            ],
            'skyblock' => [
                'label' => 'Skyblock',
                'icon' => 'island',
                'prompt' => 'Build a Skyblock server with island creation, island upgrades, minions, island top rankings and a cobblestone generator economy.',
            ],
            'towny' => [
                'label' => 'Towny',
                'icon' => 'home',
                'prompt' => 'Build a Towny server with town creation, nation wars, taxes, town chat, land claiming and a medieval town theme.',
            ],
            'pvp' => [
                'label' => 'PvP Server',
                'icon' => 'sword',
                'prompt' => 'Build a PvP server with kits, arenas, killstreaks, elo ranking, no fall damage in arenas and respawn events.',
            ],
            'prison' => [
                'label' => 'Prison',
                'icon' => 'bars',
                'prompt' => 'Build a prison server with ranks A to Z, mine ranks, sell-all economy, pickaxe upgrades and prestige system.',
            ],
            'rpg' => [
                'label' => 'RPG Server',
                'icon' => 'map',
                'prompt' => 'Build an RPG server with custom classes, skills, dungeons, mob levels, loot tiers and a fantasy quest line.',
            ],
            'adventure' => [
                'label' => 'Adventure',
                'icon' => 'compass',
                'prompt' => 'Build an adventure server with custom quests, npc dialogue, dungeons, world border exploration and a storytelling MOTD.',
            ],
            'creative' => [
                'label' => 'Creative/Build',
                'icon' => 'brush',
                'prompt' => 'Build a creative plot server with plot claims, WorldEdit for members, large render distance and anti-grief rollback.',
            ],
        ];
    }

    public static function find(string $slug): ?array
    {
        return self::all()[$slug] ?? null;
    }

    /** @return array<int, string> */
    public static function slugs(): array
    {
        return array_keys(self::all());
    }
}
