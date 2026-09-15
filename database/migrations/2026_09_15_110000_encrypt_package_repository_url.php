<?php

use App\Support\CredentialUrl;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * `packages.repository_url` legitimately carries a git credential — it is the only way
     * to authenticate a remote when the dedicated `repository_token` was skipped, and
     * App\Support\CredentialUrl documents it as a supported carrier. It was the one such
     * column stored in the clear, while `repository_token` beside it has always been an
     * `encrypted` cast.
     *
     * Widened to `text` first, and that is not a nicety: a 58-character repository URL
     * encrypts to over 300 characters, so on the previous `varchar(255)` the very first
     * save after the cast would have failed.
     *
     * The rows are encrypted here rather than by the model, because once the cast is in
     * place reading a plaintext row throws a DecryptException — the migration has to move
     * the data while it can still read it. Rows that are already ciphertext are left alone,
     * so re-running this is harmless.
     */
    public function up(): void
    {
        Schema::table('packages', function (Blueprint $table) {
            $table->text('repository_url')->nullable()->change();
        });

        DB::table('packages')
            ->whereNotNull('repository_url')
            ->orderBy('id')
            ->chunkById(200, function ($rows): void {
                foreach ($rows as $row) {
                    // Already encrypted (a re-run, or a row written after the cast landed):
                    // decrypting succeeds, so leave it. Anything else is plaintext.
                    try {
                        Crypt::decryptString($row->repository_url);

                        continue;
                    } catch (Throwable) {
                        // Plaintext — encrypt it below.
                    }

                    DB::table('packages')->where('id', $row->id)->update([
                        'repository_url' => Crypt::encryptString($row->repository_url),
                    ]);
                }
            });
    }

    /**
     * Reverses the encryption so the column is readable again before narrowing it back.
     *
     * A URL that no longer fits `varchar(255)` would be truncated by the narrowing — which
     * for a credential-bearing URL means a silently broken remote rather than an error. So
     * the width is left alone on the way down: `text` holds everything `varchar(255)` did,
     * and leaving it wide costs nothing.
     */
    public function down(): void
    {
        DB::table('packages')
            ->whereNotNull('repository_url')
            ->orderBy('id')
            ->chunkById(200, function ($rows): void {
                foreach ($rows as $row) {
                    try {
                        $plain = Crypt::decryptString($row->repository_url);
                    } catch (Throwable) {
                        // Never encrypted, or encrypted under a different APP_KEY. Leaving
                        // it as-is is the only honest option: overwriting would destroy a
                        // value nothing else holds, and a redacted note would be mistaken
                        // for a real URL.
                        Log::warning('Could not decrypt a repository_url while reverting; left untouched.', [
                            'package_id' => $row->id,
                            'value' => Str::limit(CredentialUrl::redact($row->repository_url) ?? '', 60),
                        ]);

                        continue;
                    }

                    DB::table('packages')->where('id', $row->id)->update(['repository_url' => $plain]);
                }
            });
    }
};
