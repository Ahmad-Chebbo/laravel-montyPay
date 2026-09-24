<?php

namespace AhmadChebbo\LaravelMontypay\Console;

use AhmadChebbo\LaravelMontypay\MontyPayServiceProvider;
use Illuminate\Console\Command;

class InstallCommand extends Command
{
    protected $signature = 'montypay:install
        {--force : Overwrite already published files}
        {--views : Also publish the customer return views}
        {--migrate : Run the migrations without asking}
        {--no-env : Do not touch .env / .env.example}';

    protected $description = 'Set up the MontyPay package: publish assets, add .env keys and run migrations';

    /** Keys added to .env when missing. Existing values are never overwritten. */
    protected array $envKeys = [
        'MONTYPAY_ENV' => 'sandbox',
        'MONTYPAY_SANDBOX_URL' => '',
        'MONTYPAY_SANDBOX_MERCHANT_KEY' => '',
        'MONTYPAY_SANDBOX_MERCHANT_PASSWORD' => '',
        'MONTYPAY_PRODUCTION_URL' => '',
        'MONTYPAY_PRODUCTION_MERCHANT_KEY' => '',
        'MONTYPAY_PRODUCTION_MERCHANT_PASSWORD' => '',
    ];

    public function handle(): int
    {
        $this->components->info('Installing MontyPay...');

        $this->publish('montypay-config');
        $this->publish('montypay-migrations');

        if ($this->option('views')) {
            $this->publish('montypay-views');
        }

        if (! $this->option('no-env')) {
            foreach (['.env', '.env.example'] as $file) {
                $this->addEnvKeys(base_path($file));
            }
        }

        if ($this->option('migrate') || $this->confirm('Run the migrations now?', true)) {
            $this->call('migrate');
        }

        $this->newLine();
        $this->components->info('MontyPay is installed. Next steps:');
        $this->line('  1. Fill in the MONTYPAY_SANDBOX_* values in .env (URL from your account manager,');
        $this->line('     merchant Test key, and the merchant Password). Set MONTYPAY_ENV=production');
        $this->line('     and fill MONTYPAY_PRODUCTION_* when you go live.');
        $this->line('  2. Ask MontyPay (or set in the admin panel) your notification URL to:');
        $this->line('       <fg=green>' . route('montypay.callback') . '</>');
        $this->line('  3. Listen to <fg=green>AhmadChebbo\LaravelMontypay\Events\CallbackReceived</> to fulfil orders.');
        $this->line('  4. Sandbox test card: 4111 1111 1111 1111, expiry 01/38 (success), 02/38 (decline).');

        return self::SUCCESS;
    }

    protected function publish(string $tag): void
    {
        $this->call('vendor:publish', array_filter([
            '--provider' => MontyPayServiceProvider::class,
            '--tag' => $tag,
            '--force' => $this->option('force'),
        ]));
    }

    protected function addEnvKeys(string $path): void
    {
        if (! is_file($path) || ! is_writable($path)) {
            return;
        }

        $contents = file_get_contents($path);
        $missing = [];

        // A file that already has live credentials from an earlier version must stay on
        // production; only a fresh setup starts on sandbox.
        $hasLegacyCredentials = (bool) preg_match('/^MONTYPAY_MERCHANT_KEY=\S/m', $contents);

        foreach ($this->envKeys as $key => $value) {
            if ($key === 'MONTYPAY_ENV' && $hasLegacyCredentials) {
                $value = 'production';
            }

            if (! preg_match('/^' . preg_quote($key, '/') . '=/m', $contents)) {
                // .env.example never gets a real value.
                $missing[] = $key . '=' . (str_ends_with($path, '.example') ? '' : $value);
            }
        }

        if ($missing === []) {
            return;
        }

        $separator = str_ends_with($contents, "\n") || $contents === '' ? '' : "\n";
        file_put_contents($path, $contents . $separator . "\n# MontyPay\n" . implode("\n", $missing) . "\n");

        $this->components->task('Added ' . count($missing) . ' key(s) to ' . basename($path));
    }
}
