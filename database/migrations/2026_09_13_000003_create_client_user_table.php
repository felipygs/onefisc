<?php

use Illuminate\Console\Command;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Console command running this migration, when the harness injects one.
     *
     * @var Command|null
     */
    protected $command;

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('client_user', function (Blueprint $table) {
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['client_id', 'user_id']);
        });

        $this->backfillClientUser();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('client_user');
    }

    /**
     * Link clients to same-account users whose name exactly matches the
     * legacy `contador_responsavel` string. Matched counts clients with at
     * least one same-account name match; unmatched counts non-empty
     * `contador_responsavel` clients with no match. Never creates users.
     */
    protected function backfillClientUser(): void
    {
        $matched = 0;
        $unmatched = 0;

        DB::table('clients')
            ->whereNotNull('contador_responsavel')
            ->where('contador_responsavel', '!=', '')
            ->chunkById(200, function ($clients) use (&$matched, &$unmatched) {
                foreach ($clients as $client) {
                    $responsavel = trim((string) $client->contador_responsavel);

                    if ($responsavel === '') {
                        continue;
                    }

                    $userIds = DB::table('users')
                        ->where('account_id', $client->account_id)
                        ->where('name', $responsavel)
                        ->pluck('id');

                    if ($userIds->isEmpty()) {
                        $unmatched++;

                        continue;
                    }

                    $now = now();

                    DB::table('client_user')->insertOrIgnore(
                        $userIds->map(fn ($userId) => [
                            'client_id' => $client->id,
                            'user_id' => $userId,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ])->all()
                    );

                    $matched++;
                }
            });

        $message = "backfill client_user: {$matched} matched, {$unmatched} unmatched";

        // The migrator injects no console handle into migrations, so report
        // through the running command when present and STDOUT otherwise.
        if ($this->command instanceof Command) {
            $this->command->info($message);
        } else {
            fwrite(STDOUT, $message.PHP_EOL);
        }
    }
};
