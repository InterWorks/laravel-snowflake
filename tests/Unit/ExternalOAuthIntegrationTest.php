<?php

declare(strict_types=1);

use InterWorks\SnowflakeApi\Exceptions\InvalidArgumentException;
use InterWorks\SnowflakeApi\Sql\ExternalOAuthIntegration;
use InterWorks\SnowflakeApi\Sql\UserMappingAttribute;

/**
 * Valid arguments with one override, for the rejection datasets.
 *
 * @param array<string, mixed> $overrides
 */
function createIntegrationSql(array $overrides = []): string
{
    $arguments = array_merge([
        'integrationName' => 'APP_OAUTH',
        'issuer' => 'https://app.example.com',
        'jwksUrl' => 'https://app.example.com/.well-known/jwks.json',
        'audiences' => ['https://myorg-myaccount.snowflakecomputing.com'],
    ], $overrides);

    return ExternalOAuthIntegration::createSql(...$arguments);
}

it('builds the exact statement for the defaults', function (): void {
    $sql = ExternalOAuthIntegration::createSql(
        'APP_OAUTH',
        'https://app.example.com',
        'https://app.example.com/.well-known/jwks.json',
        ['https://myorg-myaccount.snowflakecomputing.com', 'my-app'],
    );

    expect($sql)->toBe(<<<'SQL'
CREATE SECURITY INTEGRATION APP_OAUTH
  TYPE = EXTERNAL_OAUTH
  ENABLED = TRUE
  EXTERNAL_OAUTH_TYPE = CUSTOM
  EXTERNAL_OAUTH_ISSUER = 'https://app.example.com'
  EXTERNAL_OAUTH_JWS_KEYS_URL = 'https://app.example.com/.well-known/jwks.json'
  EXTERNAL_OAUTH_AUDIENCE_LIST = ('https://myorg-myaccount.snowflakecomputing.com', 'my-app')
  EXTERNAL_OAUTH_TOKEN_USER_MAPPING_CLAIM = 'email'
  EXTERNAL_OAUTH_SNOWFLAKE_USER_MAPPING_ATTRIBUTE = 'EMAIL_ADDRESS'
  EXTERNAL_OAUTH_SCOPE_MAPPING_ATTRIBUTE = 'scp'
  EXTERNAL_OAUTH_ANY_ROLE_MODE = 'ENABLE';
SQL);
});

it('builds the exact statement for login name mapping with any role mode off', function (): void {
    $sql = ExternalOAuthIntegration::createSql(
        integrationName: 'APP_OAUTH',
        issuer: 'https://app.example.com',
        jwksUrl: 'https://app.example.com/.well-known/jwks.json',
        audiences: ['my-app'],
        userMappingClaim: 'preferred_username',
        mappingAttribute: UserMappingAttribute::LoginName,
        anyRoleMode: false,
        scopeMappingAttribute: 'scope',
    );

    expect($sql)->toBe(<<<'SQL'
CREATE SECURITY INTEGRATION APP_OAUTH
  TYPE = EXTERNAL_OAUTH
  ENABLED = TRUE
  EXTERNAL_OAUTH_TYPE = CUSTOM
  EXTERNAL_OAUTH_ISSUER = 'https://app.example.com'
  EXTERNAL_OAUTH_JWS_KEYS_URL = 'https://app.example.com/.well-known/jwks.json'
  EXTERNAL_OAUTH_AUDIENCE_LIST = ('my-app')
  EXTERNAL_OAUTH_TOKEN_USER_MAPPING_CLAIM = 'preferred_username'
  EXTERNAL_OAUTH_SNOWFLAKE_USER_MAPPING_ATTRIBUTE = 'LOGIN_NAME'
  EXTERNAL_OAUTH_SCOPE_MAPPING_ATTRIBUTE = 'scope'
  EXTERNAL_OAUTH_ANY_ROLE_MODE = 'DISABLE';
SQL);
});

it('doubles single quotes in the issuer', function (): void {
    $sql = createIntegrationSql(['issuer' => "https://app.example.com/o'brien"]);

    expect($sql)->toContain("EXTERNAL_OAUTH_ISSUER = 'https://app.example.com/o''brien'");
});

it('doubles single quotes in every string argument', function (): void {
    $sql = createIntegrationSql([
        'audiences' => ["a'b", "c''d"],
        'userMappingClaim' => "e'f",
        'scopeMappingAttribute' => "g'h",
    ]);

    expect($sql)->toContain("('a''b', 'c''''d')")
        ->and($sql)->toContain("MAPPING_CLAIM = 'e''f'")
        ->and($sql)->toContain("SCOPE_MAPPING_ATTRIBUTE = 'g''h'");
});

it('doubles backslashes so a trailing backslash cannot escape the closing quote', function (): void {
    $sql = createIntegrationSql(['issuer' => 'https://app.example.com/x\\']);

    expect($sql)->toContain("EXTERNAL_OAUTH_ISSUER = 'https://app.example.com/x\\\\'");
});

it('keeps injection attempts inside the string literal', function (): void {
    $sql = createIntegrationSql(['issuer' => "x'; DROP USER admin; --"]);

    expect($sql)->toContain("EXTERNAL_OAUTH_ISSUER = 'x''; DROP USER admin; --'");
});

it('rejects invalid arguments', function (array $overrides): void {
    createIntegrationSql($overrides);
})->throws(InvalidArgumentException::class)->with([
    'newline in issuer' => [['issuer' => "https://a.example\nTYPE = X"]],
    'carriage return in issuer' => [['issuer' => "https://a.example\r"]],
    'nul in issuer' => [['issuer' => "https://a.example\0"]],
    'tab in claim' => [['userMappingClaim' => "em\tail"]],
    'newline in scope attribute' => [['scopeMappingAttribute' => "scp\n"]],
    'newline in audience' => [['audiences' => ["ok", "bad\naudience"]]],
    'newline in jwks url' => [['jwksUrl' => "https://a.example/jwks\n"]],
    'empty issuer' => [['issuer' => '']],
    'empty claim' => [['userMappingClaim' => '']],
    'empty audience' => [['audiences' => ['']]],
    'no audiences' => [['audiences' => []]],
    'http jwks url' => [['jwksUrl' => 'http://a.example/jwks']],
    'jwks url without host' => [['jwksUrl' => 'https://']],
    'jwks url not a url' => [['jwksUrl' => 'not a url']],
    'jwks url with space' => [['jwksUrl' => 'https://a.example/jw ks']],
    'invalid utf-8 issuer' => [['issuer' => "https://a.example/\xB1"]],
    'quoted integration name' => [['integrationName' => '"my integration"']],
    'integration name with space' => [['integrationName' => 'A B']],
    'integration name injection' => [['integrationName' => 'X; DROP USER admin']],
    'empty integration name' => [['integrationName' => '']],
]);

it('limits the integration to the allowed roles, exactly as given, before the any-role line', function (): void {
    $sql = createIntegrationSql(['anyRoleMode' => false, 'allowedRoles' => ['curator_reader', 'REPORTING']]);

    expect($sql)->toContain(<<<'SQL'
  EXTERNAL_OAUTH_SCOPE_MAPPING_ATTRIBUTE = 'scp'
  EXTERNAL_OAUTH_ALLOWED_ROLES_LIST = ('curator_reader', 'REPORTING')
  EXTERNAL_OAUTH_ANY_ROLE_MODE = 'DISABLE';
SQL)
        ->and(createIntegrationSql())->not->toContain('ALLOWED_ROLES_LIST');

    $withKey = ExternalOAuthIntegration::createSqlWithPublicKey(
        integrationName: 'APP_OAUTH',
        issuer: 'https://app.example.com',
        rsaPublicKey: 'MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8A',
        audiences: ['my-app'],
        allowedRoles: ['CURATOR_READER'],
    );

    expect($withKey)->toContain("  EXTERNAL_OAUTH_ALLOWED_ROLES_LIST = ('CURATOR_READER')\n");
});

it('refuses an allowed role that is not an unquoted identifier', function (string $role): void {
    createIntegrationSql(['allowedRoles' => [$role]]);
})->throws(InvalidArgumentException::class)->with([
    'empty' => [''],
    'quoted' => ['"Reader"'],
    'space' => ['READ ER'],
    'injection' => ["R'); DROP USER admin; --"],
]);

it('emits CREATE OR REPLACE when asked', function (): void {
    $sql = createIntegrationSql(['orReplace' => true]);

    expect($sql)->toStartWith('CREATE OR REPLACE SECURITY INTEGRATION APP_OAUTH')
        ->and(createIntegrationSql())->toStartWith('CREATE SECURITY INTEGRATION APP_OAUTH');
});

it('builds the exact pasted-key statement from a PEM block', function (): void {
    $pem = "-----BEGIN PUBLIC KEY-----\nMIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8A\nMIIBCgKCAQEA0Z3VS5JJcds3xfn/ygWy\n-----END PUBLIC KEY-----\n";

    $sql = ExternalOAuthIntegration::createSqlWithPublicKey(
        integrationName: 'APP_OAUTH',
        issuer: 'https://app.example.com',
        rsaPublicKey: $pem,
        audiences: ['https://myorg-myaccount.snowflakecomputing.com'],
        orReplace: true,
    );

    expect($sql)->toBe(<<<'SQL'
CREATE OR REPLACE SECURITY INTEGRATION APP_OAUTH
  TYPE = EXTERNAL_OAUTH
  ENABLED = TRUE
  EXTERNAL_OAUTH_TYPE = CUSTOM
  EXTERNAL_OAUTH_ISSUER = 'https://app.example.com'
  EXTERNAL_OAUTH_RSA_PUBLIC_KEY = 'MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEA0Z3VS5JJcds3xfn/ygWy'
  EXTERNAL_OAUTH_AUDIENCE_LIST = ('https://myorg-myaccount.snowflakecomputing.com')
  EXTERNAL_OAUTH_TOKEN_USER_MAPPING_CLAIM = 'email'
  EXTERNAL_OAUTH_SNOWFLAKE_USER_MAPPING_ATTRIBUTE = 'EMAIL_ADDRESS'
  EXTERNAL_OAUTH_SCOPE_MAPPING_ATTRIBUTE = 'scp'
  EXTERNAL_OAUTH_ANY_ROLE_MODE = 'ENABLE';
SQL);
});

it('accepts a bare base64 key body and matches the JWKS variant line for line otherwise', function (): void {
    $withKey = ExternalOAuthIntegration::createSqlWithPublicKey(
        'APP_OAUTH',
        'https://app.example.com',
        'MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8A',
        ['my-app'],
    );
    $withUrl = createIntegrationSql(['audiences' => ['my-app']]);

    $strip = static fn (string $sql): array => array_values(array_filter(
        explode("\n", $sql),
        static fn (string $line): bool => ! str_contains($line, 'EXTERNAL_OAUTH_RSA_PUBLIC_KEY')
            && ! str_contains($line, 'EXTERNAL_OAUTH_JWS_KEYS_URL'),
    ));

    expect($withKey)->toContain("EXTERNAL_OAUTH_RSA_PUBLIC_KEY = 'MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8A'")
        ->and($strip($withKey))->toBe($strip($withUrl));
});

it('rejects an unusable public key', function (string $rsaPublicKey): void {
    ExternalOAuthIntegration::createSqlWithPublicKey('APP_OAUTH', 'https://app.example.com', $rsaPublicKey, ['my-app']);
})->throws(InvalidArgumentException::class)->with([
    'empty' => [''],
    'only armour' => ["-----BEGIN PUBLIC KEY-----\n-----END PUBLIC KEY-----"],
    'quote inside' => ["MIIB'ABC"],
    'injection' => ["MIIB'); DROP USER admin; --"],
    'non base64' => ['not a key!'],
    // The private-key headers are split so secret scanners do not mistake these truncated fixtures for real keys.
    'private key' => ["-----BEGIN PRIVATE" . " KEY-----\nMIIEvQIBADANBgkqhkiG9w0BAQEFAASC\n-----END PRIVATE" . " KEY-----\n"],
    'pkcs1 private key' => [
        "-----BEGIN RSA PRIVATE" . " KEY-----\nMIIEpAIBAAKCAQEA0Z3VS5JJcds3xfn\n-----END RSA PRIVATE" . " KEY-----",
    ],
    'pkcs1 public key' => ["-----BEGIN RSA PUBLIC KEY-----\nMIIBCgKCAQEA0Z3VS5JJcds3xfn/ygWy\n-----END RSA PUBLIC KEY-----"],
    'certificate' => ["-----BEGIN CERTIFICATE-----\nMIIDdzCCAl+gAwIBAgIEAgAAuTANBgkq\n-----END CERTIFICATE-----"],
    'two blocks' => [
        "-----BEGIN PUBLIC KEY-----\nMIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8A\n-----END PUBLIC KEY-----\n"
        . "-----BEGIN PUBLIC KEY-----\nMIIBCgKCAQEA0Z3VS5JJcds3xfn/ygWy\n-----END PUBLIC KEY-----\n",
    ],
    'mismatched armour' => ["-----BEGIN PUBLIC KEY-----\nMIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8A\n-----END PRIVATE KEY-----"],
]);

it('builds the verify statement with quotes doubled', function (): void {
    expect(ExternalOAuthIntegration::verifyTokenSql('aaa.bbb.ccc'))
        ->toBe("SELECT SYSTEM\$VERIFY_EXTERNAL_OAUTH_TOKEN('aaa.bbb.ccc');")
        ->and(ExternalOAuthIntegration::verifyTokenSql("a'b"))
        ->toBe("SELECT SYSTEM\$VERIFY_EXTERNAL_OAUTH_TOKEN('a''b');");
});

it('rejects an unusable token for the verify statement', function (string $jwt): void {
    ExternalOAuthIntegration::verifyTokenSql($jwt);
})->throws(InvalidArgumentException::class)->with([
    'empty' => [''],
    'newline' => ["aaa\nbbb"],
    'nul' => ["aaa\0bbb"],
]);

it('does not echo a rejected value in the message', function (): void {
    try {
        ExternalOAuthIntegration::verifyTokenSql("secret-token\nrest");
    } catch (InvalidArgumentException $e) {
        expect($e->getMessage())->not->toContain('secret-token')->and($e->getPrevious())->toBeNull();

        return;
    }

    $this->fail('Expected an InvalidArgumentException.');
});
