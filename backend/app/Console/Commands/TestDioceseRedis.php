<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Redis;

class TestDioceseRedis extends Command
{
    protected $signature = 'diocese:test-redis';
    protected $description = 'Test Redis connection for Diocese system';

    public function handle()
    {
        try {
            $this->info('Testing Redis connection...');

            // Test basic connection
            $result = Redis::ping();
            $this->info("✅ Redis ping: " . $result);

            // Test cache connection
            Redis::connection('cache')->ping();
            $this->info("✅ Cache connection: OK");

            // Test sessions connection
            Redis::connection('sessions')->ping();
            $this->info("✅ Sessions connection: OK");

            // Test diocese-specific data
            Redis::set('diocese_name', config('app.diocese_name', 'Makueni West Diocese'));
            $diocese = Redis::get('diocese_name');
            $this->info("✅ Diocese data test: " . $diocese);

            $this->info('🎉 All Redis connections working perfectly!');

        } catch (\Exception $e) {
            $this->error("❌ Redis error: " . $e->getMessage());
            $this->info("💡 Make sure Redis is running in Laragon");
        }
    }
}
