<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Carbon;

/**
 * Initial seeder.
 *
 * Creates:
 *   1. Yellowfirst tenant
 *   2. Karna's user account (you'll set the password yourself by running
 *      php artisan tinker and updating it — never commit a real password)
 *   3. Default pipeline stages (Prospecting → ... → Closed Won/Lost)
 *   4. Global rejection reasons (tenant_id NULL = available to all tenants)
 *   5. Yellowfirst's three ICPs from docs/02-icp-profiles.md
 *   6. Default business units (US, EU, India/APAC, Australia)
 *
 * Run with: php artisan db:seed
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Yellowfirst tenant
        $tenantId = DB::table('tenants')->insertGetId([
            'name' => 'Yellowfirst',
            'slug' => 'yellowfirst',
            'domain' => 'app.yellowfirst.com',
            'theme_config' => json_encode([
                'brand_primary' => '#2563EB',
                'brand_accent' => '#F59E0B',
            ]),
            'timezone' => 'America/Chicago',
            'daily_quota_small' => 5,
            'daily_quota_mid' => 3,
            'daily_quota_enterprise' => 1,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 2. Karna's user (placeholder password — change immediately via tinker)
        DB::table('users')->insert([
            'tenant_id' => $tenantId,
            'name' => 'Karna Shukla',
            'email' => 'karna@yellowfirst.com',
            'password' => Hash::make('CHANGE_ME_IMMEDIATELY'),
            'email_verified_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 3. Default pipeline stages
        $stages = [
            ['code' => 'prospecting',    'name' => 'Prospecting',    'sort_order' => 1, 'color' => '#6366F1'],
            ['code' => 'qualification',  'name' => 'Qualification',  'sort_order' => 2, 'color' => '#06B6D4'],
            ['code' => 'proposal',       'name' => 'Proposal Sent',  'sort_order' => 3, 'color' => '#F59E0B'],
            ['code' => 'negotiation',    'name' => 'Negotiation',    'sort_order' => 4, 'color' => '#8B5CF6'],
            ['code' => 'closed_won',     'name' => 'Closed Won',     'sort_order' => 5, 'color' => '#10B981', 'is_terminal' => true, 'terminal_outcome' => 'won'],
            ['code' => 'closed_lost',    'name' => 'Closed Lost',    'sort_order' => 6, 'color' => '#EF4444', 'is_terminal' => true, 'terminal_outcome' => 'lost'],
        ];

        foreach ($stages as $stage) {
            DB::table('pipeline_stages')->insert(array_merge($stage, [
                'tenant_id' => $tenantId,
                'is_terminal' => $stage['is_terminal'] ?? false,
                'terminal_outcome' => $stage['terminal_outcome'] ?? null,
                'created_at' => now(),
                'updated_at' => now(),
            ]));
        }

        // 4. Global rejection reasons (tenant_id NULL = available to all tenants)
        $reasons = [
            ['code' => 'wrong_company',     'label' => 'Wrong company',          'sort_order' => 1, 'affects_future_research' => true],
            ['code' => 'wrong_person',      'label' => 'Wrong person at the company', 'sort_order' => 2, 'affects_future_research' => true],
            ['code' => 'already_approached','label' => 'Already approached',     'sort_order' => 3, 'affects_future_research' => false],
            ['code' => 'already_customer',  'label' => 'Already a customer',     'sort_order' => 4, 'affects_future_research' => true],
            ['code' => 'too_small',         'label' => 'Too small for our ICP',  'sort_order' => 5, 'affects_future_research' => true],
            ['code' => 'bad_timing',        'label' => 'Bad timing',             'sort_order' => 6, 'affects_future_research' => false],
            ['code' => 'other',             'label' => 'Other',                  'sort_order' => 7, 'affects_future_research' => false],
        ];

        foreach ($reasons as $reason) {
            DB::table('rejection_reasons')->insert(array_merge($reason, [
                'tenant_id' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]));
        }

        // 5. Yellowfirst's three ICPs (from docs/02-icp-profiles.md)
        $icps = [
            [
                'code' => 'series_a_c_saas',
                'name' => 'Series A-C SaaS',
                'weight' => 1.50,
                'industries' => ['B2B SaaS', 'Vertical SaaS', 'Developer Tools', 'API Infrastructure'],
                'geography' => ['US', 'CA', 'GB', 'EU'],
                'size_min' => '20',
                'size_max' => '200',
                'funding_stages' => ['series_a', 'series_b', 'series_c'],
                'target_titles_primary' => ['CTO', 'VP Engineering', 'Head of Engineering', 'Co-founder'],
                'target_titles_secondary' => ['VP Product', 'Head of Product'],
                'signal_emphasis' => [
                    'funding_round' => 1.5,
                    'hiring_in_target_function' => 1.5,
                    'hiring_surge' => 1.3,
                    'leadership_change' => 1.2,
                ],
                'pitch_angle' => 'Just funded, scaling fast, hiring is the bottleneck. Pitch the "two-person product team" math: roles → systems.',
                'sort_order' => 1,
            ],
            [
                'code' => 'late_stage_scaleup',
                'name' => 'Late-stage scaleups',
                'weight' => 1.20,
                'industries' => ['B2B SaaS', 'FinTech', 'Enterprise Software'],
                'geography' => ['US', 'CA', 'GB', 'EU'],
                'size_min' => '200',
                'size_max' => '2000',
                'funding_stages' => ['series_c', 'series_d', 'series_e', 'pre_ipo'],
                'target_titles_primary' => ['CPO', 'CTO', 'VP Product', 'VP Engineering', 'Chief Innovation Officer'],
                'target_titles_secondary' => ['Head of New Initiatives', 'GM'],
                'signal_emphasis' => [
                    'product_launch' => 1.4,
                    'leadership_change' => 1.4,
                    'funding_round' => 1.2,
                ],
                'pitch_angle' => 'New bet, new leader, fresh capital. Pitch Yellowfirst as the "fast-lane execution arm" for the new product line — not the core business.',
                'sort_order' => 2,
            ],
            [
                'code' => 'enterprise_innovation',
                'name' => 'Enterprise innovation',
                'weight' => 1.00,
                'industries' => ['Financial Services', 'Insurance', 'Healthcare', 'Retail', 'Manufacturing'],
                'geography' => ['US', 'CA', 'GB'],
                'size_min' => '5000',
                'size_max' => null,
                'funding_stages' => ['public', 'private_equity'],
                'target_titles_primary' => ['Chief Digital Officer', 'Chief Innovation Officer', 'VP Innovation', 'Head of Digital'],
                'target_titles_secondary' => ['VP Customer Experience', 'Head of Transformation'],
                'signal_emphasis' => [
                    'leadership_change' => 1.5,
                    'conference_buzz' => 1.3,
                    'product_launch' => 1.2,
                ],
                'pitch_angle' => 'New CXO, 90 days in, looking to establish wins. Yellowfirst as the "fast lane" partner for first visible initiative.',
                'sort_order' => 3,
            ],
        ];

        foreach ($icps as $icp) {
            DB::table('icp_profiles')->insert([
                'tenant_id' => $tenantId,
                'code' => $icp['code'],
                'name' => $icp['name'],
                'weight' => $icp['weight'],
                'industries' => json_encode($icp['industries']),
                'geography' => json_encode($icp['geography']),
                'size_min' => $icp['size_min'],
                'size_max' => $icp['size_max'],
                'funding_stages' => json_encode($icp['funding_stages']),
                'target_titles_primary' => json_encode($icp['target_titles_primary']),
                'target_titles_secondary' => json_encode($icp['target_titles_secondary']),
                'signal_emphasis' => json_encode($icp['signal_emphasis']),
                'pitch_angle' => $icp['pitch_angle'],
                'active' => true,
                'sort_order' => $icp['sort_order'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // 6. Default business units
        $bus = [
            ['code' => 'us',          'name' => 'US',           'countries' => ['US']],
            ['code' => 'eu',          'name' => 'EU',           'countries' => ['DE', 'FR', 'NL', 'ES', 'IT', 'SE', 'DK', 'NO', 'FI', 'IE']],
            ['code' => 'india_apac',  'name' => 'India / APAC', 'countries' => ['IN', 'SG', 'JP', 'KR', 'HK']],
            ['code' => 'australia',   'name' => 'Australia',    'countries' => ['AU', 'NZ']],
        ];

        foreach ($bus as $bu) {
            DB::table('business_units')->insert([
                'tenant_id' => $tenantId,
                'code' => $bu['code'],
                'name' => $bu['name'],
                'countries' => json_encode($bu['countries']),
                'active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->command->info('Seeded Yellowfirst tenant (id: ' . $tenantId . ')');
        $this->command->warn('IMPORTANT: Change Karna\'s password before going live:');
        $this->command->warn('   php artisan tinker');
        $this->command->warn('   >>> User::where(\'email\', \'karna@yellowfirst.com\')->update([\'password\' => Hash::make(\'your_real_password\')]);');
    }
}
