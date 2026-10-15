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
     * The CREATE SECURITY INTEGRATION statement for an External OAuth custom issuer.
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
    ): string {
        Identifier::assertUnquoted($integrationName, 'integration name');

        if ($audiences === []) {
            throw new InvalidArgumentException('The audiences must be a non-empty list of strings.');
        }

        if (! self::isHttpsUrl($jwksUrl)) {
            throw new InvalidArgumentException('The JWKS URL must be an https URL.');
        }

        $audienceList = implode(', ', array_map(
            static fn (string $audience): string => self::literal($audience, 'audience'),
            $audiences
        ));

        return implode("\n", [
            'CREATE SECURITY INTEGRATION ' . $integrationName,
            '  TYPE = EXTERNAL_OAUTH',
            '  ENABLED = TRUE',
            '  EXTERNAL_OAUTH_TYPE = CUSTOM',
            '  EXTERNAL_OAUTH_ISSUER = ' . self::literal($issuer, 'issuer'),
            '  EXTERNAL_OAUTH_JWS_KEYS_URL = ' . self::literal($jwksUrl, 'JWKS URL'),
            '  EXTERNAL_OAUTH_AUDIENCE_LIST = (' . $audienceList . ')',
            '  EXTERNAL_OAUTH_TOKEN_USER_MAPPING_CLAIM = ' . self::literal($userMappingClaim, 'user mapping claim'),
            '  EXTERNAL_OAUTH_SNOWFLAKE_USER_MAPPING_ATTRIBUTE = '
                . self::literal($mappingAttribute->value, 'attribute'),
            '  EXTERNAL_OAUTH_SCOPE_MAPPING_ATTRIBUTE = ' . self::literal($scopeMappingAttribute, 'scope claim'),
            '  EXTERNAL_OAUTH_ANY_ROLE_MODE = ' . ($anyRoleMode ? "'ENABLE'" : "'DISABLE'") . ';',
        ]);
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
}
