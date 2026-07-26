<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OperatorComposeContractTest extends TestCase
{
    #[Test]
    public function compose_profile_separates_runtime_and_migration_authority(): void
    {
        $compose = file_get_contents(dirname(__DIR__, 2).'/compose.operator.yml');

        $this->assertStringContainsString('mariadb:11.4@sha256:', $compose);
        $this->assertStringContainsString('profiles: ["tools"]', $compose);
        $this->assertStringContainsString('command: ["php", "artisan", "migrate"', $compose);
        $this->assertStringContainsString('command: ["php", "artisan", "schedule:work"', $compose);
        $this->assertStringContainsString('read_only: true', $compose);
        $this->assertStringContainsString('no-new-privileges:true', $compose);
        $this->assertStringContainsString('cap_drop:', $compose);
    }

    #[Test]
    public function compose_profile_has_no_inline_secret_or_default_credential(): void
    {
        $compose = file_get_contents(dirname(__DIR__, 2).'/compose.operator.yml');

        $this->assertStringContainsString('APP_KEY_FILE: /run/secrets/app_key', $compose);
        $this->assertStringContainsString('DB_PASSWORD_FILE: /run/secrets/db_password', $compose);
        $this->assertStringNotContainsString('APP_KEY: base64:', $compose);
        $this->assertStringNotContainsString('MARIADB_ROOT_PASSWORD:', $compose);
        $this->assertStringNotContainsString('iicp_dir_pass', $compose);
    }
}
