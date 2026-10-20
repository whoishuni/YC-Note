<?php
/**
 * Central no-Emoji policy for active UI, mail and generated documents.
 */
class EmojiPolicy
{
    private const PATTERN = '/(?:'
        . '\p{Extended_Pictographic}\x{FE0F}?'
        . '(?:\x{200D}\p{Extended_Pictographic}\x{FE0F}?)*'
        . '|[\x{1F1E6}-\x{1F1FF}]{2}'
        . '|[#*0-9]\x{FE0F}?\x{20E3}'
        . '|[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}\x{2300}-\x{23FF}]\x{FE0F}?'
        . '(?:\x{200D}[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}\x{2300}-\x{23FF}]\x{FE0F}?)*'
        . '|[\x{FE0F}\x{200D}]'
        . ')/u';

    private const HTML_ENTITY_PATTERN = '/&(?:#[0-9]{1,7}|#x[0-9A-Fa-f]{1,6}|copy);/i';

    public static function remove($value)
    {
        $value = (string) $value;
        $cleaned = preg_replace(self::PATTERN, '', $value);
        if ($cleaned === null) {
            return $value;
        }

        $withoutEntities = preg_replace_callback(
            self::HTML_ENTITY_PATTERN,
            static function (array $match): string {
                $decoded = html_entity_decode($match[0], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                return preg_match(self::PATTERN, $decoded) === 1 ? '' : $match[0];
            },
            $cleaned
        );

        return $withoutEntities ?? $cleaned;
    }

    public static function cleanArray(array $values)
    {
        foreach ($values as $key => $value) {
            if (is_array($value)) {
                $values[$key] = self::cleanArray($value);
            } elseif (is_string($value)) {
                $values[$key] = self::remove($value);
            }
        }
        return $values;
    }
}
