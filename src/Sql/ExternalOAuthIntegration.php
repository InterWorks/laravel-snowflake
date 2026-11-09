<?php

declare(strict_types=1);

namespace InterWorks\SnowflakeApi\Sql;

use InterWorks\SnowflakeApi\Exceptions\InvalidArgumentException;
use InterWorks\SnowflakeApi\Support\Identifier;
use SensitiveParameter;

/**
 * Builds the SQL text an administrator runs to set up External OAuth with a custom issuer.
 *
 * The package only produces text. It never executes it and never inspects SQL.
 */
final class ExternalOAuthIntegration
{
    /**
     * The statement both public builders share; only the key source line differs.
     *
     * @param list<string> $audiences
     * @param list<string> $allowedRoles
     */
    private static function build(
        string $integrationName,
        string $issuer,
        string $keySourceLine,
        array $audiences,
        string $userMappingClaim,
        UserMappingAttribute $mappingAttribute,
        bool $anyRoleMode,
        string $scopeMappingAttribute,
        bool $orReplace,
        array $allowedRoles,
    ): string {
        Identifier::assertUnquoted($integrationName, 'integration name');

        if ($audiences === []) {
            throw new InvalidArgumentException('The audiences must be a non-empty list of strings.');
        }

        $audienceList = implode(', ', array_map(
            static fn (string $audience): string => self::literal($audience, 'audience'),
            $audiences
        ));

        // The list holds role names as string literals, written exactly as given so they match the token's scope.
        $allowedRoleLines = [];
        if ($allowedRoles !== []) {
            $allowedRoleLines[] = '  EXTERNAL_OAUTH_ALLOWED_ROLES_LIST = (' . implode(', ', array_map(
                static fn (string $role): string => self::literal(
                    Identifier::assertUnquoted($role, 'allowed role'),
                    'allowed role'
                ),
                $allowedRoles
            )) . ')';
        }

        return implode("\n", [
            ($orReplace ? 'CREATE OR REPLACE' : 'CREATE') . ' SECURITY INTEGRATION ' . $integrationName,
            '  TYPE = EXTERNAL_OAUTH',
            '  ENABLED = TRUE',
            '  EXTERNAL_OAUTH_TYPE = CUSTOM',
            '  EXTERNAL_OAUTH_ISSUER = ' . self::literal($issuer, 'issuer'),
            $keySourceLine,
            '  EXTERNAL_OAUTH_AUDIENCE_LIST = (' . $audienceList . ')',
            '  EXTERNAL_OAUTH_TOKEN_USER_MAPPING_CLAIM = ' . self::literal($userMappingClaim, 'user mapping claim'),
            '  EXTERNAL_OAUTH_SNOWFLAKE_USER_MAPPING_ATTRIBUTE = '
                . self::literal($mappingAttribute->value, 'attribute'),
            '  EXTERNAL_OAUTH_SCOPE_MAPPING_ATTRIBUTE = ' . self::literal($scopeMappingAttribute, 'scope claim'),
            ...$allowedRoleLines,
            '  EXTERNAL_OAUTH_ANY_ROLE_MODE = ' . ($anyRoleMode ? "'ENABLE'" : "'DISABLE'") . ';',
        ]);
    }
    /**
     * The CREATE SECURITY INTEGRATION statement for an External OAuth custom issuer whose keys Snowflake fetches
     * from a JWKS URL. Keys can rotate on the issuer without a change in Snowflake.
     *
     * String literals have single quotes and backslashes doubled. Any string containing a control character, a
     * newline or a NUL byte is refused.
     *
     * @param string       $integrationName       an unquoted identifier
     * @param string       $issuer                must equal the token's iss claim byte for byte
     * @param string       $jwksUrl               an https URL
     * @param list<string> $audiences             non-empty
     * @param string       $userMappingClaim      the token claim that identifies the user
     * @param string       $scopeMappingAttribute the token claim that carries the scopes
     * @param bool         $orReplace             emit CREATE OR REPLACE so the statement can be re-run
     * @param list<string> $allowedRoles          unquoted role names; when given, Snowflake accepts tokens for these
     *                                            roles only (EXTERNAL_OAUTH_ALLOWED_ROLES_LIST)
     *
     * @throws InvalidArgumentException when an argument breaks one of the rules above.
     */
    public static function createSql(
        string $integrationName,
        string $issuer,
        string $jwksUrl,
        array $audiences,
        string $userMappingClaim = 'email',
        UserMappingAttribute $mappingAttribute = UserMappingAttribute::EmailAddress,
        bool $anyRoleMode = true,
        string $scopeMappingAttribute = 'scp',
        bool $orReplace = false,
        array $allowedRoles = [],
    ): string {
        if (! self::isHttpsUrl($jwksUrl)) {
            throw new InvalidArgumentException('The JWKS URL must be an https URL.');
        }

        return self::build(
            $integrationName,
            $issuer,
            '  EXTERNAL_OAUTH_JWS_KEYS_URL = ' . self::literal($jwksUrl, 'JWKS URL'),
            $audiences,
            $userMappingClaim,
            $mappingAttribute,
            $anyRoleMode,
            $scopeMappingAttribute,
            $orReplace,
            $allowedRoles,
        );
    }

    /**
     * The CREATE SECURITY INTEGRATION statement for an External OAuth custom issuer whose public key is pasted into
     * Snowflake, for issuers Snowflake cannot reach. A key rotation on the issuer needs the statement run again.
     *
     * The key is accepted as a PEM block or as its bare base64 body; the PEM armour and all whitespace are stripped,
     * because EXTERNAL_OAUTH_RSA_PUBLIC_KEY takes the base64 body on one line. Everything else is as for createSql().
     *
     * @param string       $integrationName       an unquoted identifier
     * @param string       $issuer                must equal the token's iss claim byte for byte
     * @param string       $rsaPublicKey          the issuer's RSA public key, PEM or bare base64
     * @param list<string> $audiences             non-empty
     * @param string       $userMappingClaim      the token claim that identifies the user
     * @param string       $scopeMappingAttribute the token claim that carries the scopes
     * @param bool         $orReplace             emit CREATE OR REPLACE so the statement can be re-run
     * @param list<string> $allowedRoles          unquoted role names; when given, Snowflake accepts tokens for these
     *                                            roles only (EXTERNAL_OAUTH_ALLOWED_ROLES_LIST)
     *
     * @throws InvalidArgumentException when an argument breaks one of the rules above.
     */
    public static function createSqlWithPublicKey(
        string $integrationName,
        string $issuer,
        string $rsaPublicKey,
        array $audiences,
        string $userMappingClaim = 'email',
        UserMappingAttribute $mappingAttribute = UserMappingAttribute::EmailAddress,
        bool $anyRoleMode = true,
        string $scopeMappingAttribute = 'scp',
        bool $orReplace = false,
        array $allowedRoles = [],
    ): string {
        $keyBody = (string) preg_replace('/-----[^-]+-----|\s+/', '', $rsaPublicKey);

        if ($keyBody === '' || preg_match('#^[A-Za-z0-9+/]+=*$#', $keyBody) !== 1) {
            throw new InvalidArgumentException('The RSA public key must be a PEM block or its base64 body.');
        }

        return self::build(
            $integrationName,
            $issuer,
            '  EXTERNAL_OAUTH_RSA_PUBLIC_KEY = ' . self::literal($keyBody, 'RSA public key'),
            $audiences,
            $userMappingClaim,
            $mappingAttribute,
            $anyRoleMode,
            $scopeMappingAttribute,
            $orReplace,
            $allowedRoles,
        );
    }

    private static function isHttpsUrl(string $url): bool
    {
        $parts = parse_url($url);

        return is_array($parts)
            && strtolower($parts['scheme'] ?? '') === 'https'
            && ($parts['host'] ?? '') !== ''
            && preg_match('/[\x00-\x20\x7f]/', $url) !== 1;
    }

    /**
     * A single-quoted Snowflake string literal. The error message never contains the rejected value.
     */
    private static function literal(string $value, string $field): string
    {
        if ($value === '') {
            throw new InvalidArgumentException(sprintf('The %s must not be empty.', $field));
        }

        if (preg_match('/[\x00-\x1f\x7f]/', $value) === 1) {
            throw new InvalidArgumentException(sprintf('The %s must not contain control characters.', $field));
        }

        if (! mb_check_encoding($value, 'UTF-8')) {
            throw new InvalidArgumentException(sprintf('The %s must be valid UTF-8.', $field));
        }

        return "'" . str_replace(['\\', "'"], ['\\\\', "''"], $value) . "'";
    }

    /**
     * The statement that asks Snowflake whether it accepts an External OAuth token.
     *
     * @throws InvalidArgumentException when the token is empty or contains a control character.
     */
    public static function verifyTokenSql(#[SensitiveParameter] string $jwt): string
    {
        return 'SELECT SYSTEM$VERIFY_EXTERNAL_OAUTH_TOKEN(' . self::literal($jwt, 'token') . ');';
    }
}
