<?php

class sfYMLDumper
{
    public function dump($input, $inline = 0, $indent = 0)
    {
        $output = '';
        $prefix = $indent ? str_repeat(' ', $indent) : '';
        if ($inline <= 0 || !is_array($input) || empty($input)) {
            $output .= $prefix . sfYMLInline::dump($input);
        } else {
            $isAHash = array_keys($input) !== range(0, count($input) - 1);
            foreach ($input as $key => $value) {
                $willBeInlined = $inline - 1 <= 0 || !is_array($value) || empty($value);
                $output .= sprintf('%s%s%s%s', $prefix, $isAHash ? sfYMLInline::dump($key) . ':' : '-', $willBeInlined ? ' ' : "\n", $this->dump($value, $inline - 1, $willBeInlined ? 0 : $indent + 2)) . ($willBeInlined ? "\n" : '');
            }
        }
        return $output;
    }
}

class sfYMLParser
{
    protected $offset = 0, $lines = array(), $currentLineNb = -1, $currentLine = '', $refs = array();
    public function __construct($offset = 0)
    {
        $this->offset = $offset;
    }
    public function parse($value)
    {
        $this->currentLineNb = -1;
        $this->currentLine   = '';
        $this->lines         = explode("\n", $this->cleanup($value));
        if (function_exists('mb_internal_encoding') && ((int) ini_get('mbstring.func_overload')) & 2) {
            $mbEncoding = mb_internal_encoding();
            mb_internal_encoding('UTF-8');
        }
        $data = array();
        while ($this->moveToNextLine()) {
            if ($this->isCurrentLineEmpty()) {
                continue;
            }
            if (preg_match('#^\t+#', $this->currentLine)) {
                throw new InvalidArgumentException(sprintf('A YAML file cannot contain tabs as indentation at line %d (%s).', $this->getRealCurrentLineNb() + 1, $this->currentLine));
            }
            $isRef = $isInPlace = $isProcessed = false;
            if (preg_match('#^\-((?P<leadspaces>\s+)(?P<value>.+?))?\s*$#u', $this->currentLine, $values)) {
                if (isset($values['value']) && preg_match('#^&(?P<ref>[^ ]+) *(?P<value>.*)#u', $values['value'], $matches)) {
                    $isRef           = $matches['ref'];
                    $values['value'] = $matches['value'];
                }
                if (!isset($values['value']) || '' == trim($values['value'], ' ') || 0 === strpos(ltrim($values['value'], ' '), '#')) {
                    $c      = $this->getRealCurrentLineNb() + 1;
                    $parser = new sfYMLParser($c);
                    $parser->refs =& $this->refs;
                    $data[] = $parser->parse($this->getNextEmbedBlock());
                } else {
                    if (isset($values['leadspaces']) && ' ' == $values['leadspaces'] && preg_match('#^(?P<key>' . sfYMLInline::REGEX_QUOTED_STRING . '|[^ \'"\{].*?) *\:(\s+(?P<value>.+?))?\s*$#u', $values['value'], $matches)) {
                        $c      = $this->getRealCurrentLineNb();
                        $parser = new sfYMLParser($c);
                        $parser->refs =& $this->refs;
                        $block = $values['value'];
                        if (!$this->isNextLineIndented()) {
                            $block .= "\n" . $this->getNextEmbedBlock($this->getCurrentLineIndentation() + 2);
                        }
                        $data[] = $parser->parse($block);
                    } else {
                        $data[] = $this->parseValue($values['value']);
                    }
                }
            } else if (preg_match('#^(?P<key>' . sfYMLInline::REGEX_QUOTED_STRING . '|[^ \'"].*?) *\:(\s+(?P<value>.+?))?\s*$#u', $this->currentLine, $values)) {
                $key = sfYMLInline::parseScalar($values['key']);
                if ('<<' === $key) {
                    if (isset($values['value']) && '*' === substr($values['value'], 0, 1)) {
                        $isInPlace = substr($values['value'], 1);
                        if (!array_key_exists($isInPlace, $this->refs)) {
                            throw new InvalidArgumentException(sprintf('Reference "%s" does not exist at line %s (%s).', $isInPlace, $this->getRealCurrentLineNb() + 1, $this->currentLine));
                        }
                    } else {
                        if (isset($values['value']) && $values['value'] !== '') {
                            $value = $values['value'];
                        } else {
                            $value = $this->getNextEmbedBlock();
                        }
                        $c      = $this->getRealCurrentLineNb() + 1;
                        $parser = new sfYMLParser($c);
                        $parser->refs =& $this->refs;
                        $parsed = $parser->parse($value);
                        $merged = array();
                        if (!is_array($parsed)) {
                            throw new InvalidArgumentException(sprintf("YAML merge keys used with a scalar value instead of an array at line %s (%s)", $this->getRealCurrentLineNb() + 1, $this->currentLine));
                        } else if (isset($parsed[0])) {
                            foreach (array_reverse($parsed) as $parsedItem) {
                                if (!is_array($parsedItem)) {
                                    throw new InvalidArgumentException(sprintf("Merge items must be arrays at line %s (%s).", $this->getRealCurrentLineNb() + 1, $parsedItem));
                                }
                                $merged = array_merge($parsedItem, $merged);
                            }
                        } else {
                            $merged = array_merge($merged, $parsed);
                        }
                        $isProcessed = $merged;
                    }
                } else if (isset($values['value']) && preg_match('#^&(?P<ref>[^ ]+) *(?P<value>.*)#u', $values['value'], $matches)) {
                    $isRef           = $matches['ref'];
                    $values['value'] = $matches['value'];
                }
                if ($isProcessed) {
                    $data = $isProcessed;
                } else if (!isset($values['value']) || '' == trim($values['value'], ' ') || 0 === strpos(ltrim($values['value'], ' '), '#')) {
                    if ($this->isNextLineIndented()) {
                        $data[$key] = null;
                    } else {
                        $c      = $this->getRealCurrentLineNb() + 1;
                        $parser = new sfYMLParser($c);
                        $parser->refs =& $this->refs;
                        $data[$key] = $parser->parse($this->getNextEmbedBlock());
                    }
                } else {
                    if ($isInPlace) {
                        $data = $this->refs[$isInPlace];
                    } else {
                        $data[$key] = $this->parseValue($values['value']);
                    }
                }
            } else {
                if (2 == count($this->lines) && empty($this->lines[1])) {
                    $value = sfYMLInline::load($this->lines[0]);
                    if (is_array($value)) {
                        $first = reset($value);
                        if ('*' === substr($first, 0, 1)) {
                            $data = array();
                            foreach ($value as $alias) {
                                $data[] = $this->refs[substr($alias, 1)];
                            }
                            $value = $data;
                        }
                    }
                    if (isset($mbEncoding)) {
                        mb_internal_encoding($mbEncoding);
                    }
                    return $value;
                }
                switch (preg_last_error()) {
                    case PREG_INTERNAL_ERROR:
                        $error = 'Internal PCRE error on line';
                        break;
                    case PREG_BACKTRACK_LIMIT_ERROR:
                        $error = 'pcre.backtrack_limit reached on line';
                        break;
                    case PREG_RECURSION_LIMIT_ERROR:
                        $error = 'pcre.recursion_limit reached on line';
                        break;
                    case PREG_BAD_UTF8_ERROR:
                        $error = 'Malformed UTF-8 data on line';
                        break;
                    case PREG_BAD_UTF8_OFFSET_ERROR:
                        $error = 'Offset doesn\'t correspond to the begin of a valid UTF-8 code point on line';
                        break;
                    default:
                        $error = 'Unable to parse line';
                }
                throw new InvalidArgumentException(sprintf('%s %d (%s).', $error, $this->getRealCurrentLineNb() + 1, $this->currentLine));
            }
            if ($isRef) {
                $this->refs[$isRef] = end($data);
            }
        }
        if (isset($mbEncoding)) {
            mb_internal_encoding($mbEncoding);
        }
        return empty($data) ? null : $data;
    }
    protected function getRealCurrentLineNb()
    {
        return $this->currentLineNb + $this->offset;
    }
    protected function getCurrentLineIndentation()
    {
        return strlen($this->currentLine) - strlen(ltrim($this->currentLine, ' '));
    }
    protected function getNextEmbedBlock($indentation = null)
    {
        $this->moveToNextLine();
        if (null === $indentation) {
            $newIndent = $this->getCurrentLineIndentation();
            if (!$this->isCurrentLineEmpty() && 0 == $newIndent) {
                throw new InvalidArgumentException(sprintf('Indentation problem at line %d (%s)', $this->getRealCurrentLineNb() + 1, $this->currentLine));
            }
        } else {
            $newIndent = $indentation;
        }
        $data = array(
            substr($this->currentLine, $newIndent)
        );
        while ($this->moveToNextLine()) {
            if ($this->isCurrentLineEmpty()) {
                if ($this->isCurrentLineBlank()) {
                    $data[] = substr($this->currentLine, $newIndent);
                }
                continue;
            }
            $indent = $this->getCurrentLineIndentation();
            if (preg_match('#^(?P<text> *)$#', $this->currentLine, $match)) {
                $data[] = $match['text'];
            } else if ($indent >= $newIndent) {
                $data[] = substr($this->currentLine, $newIndent);
            } else if (0 == $indent) {
                $this->moveToPreviousLine();
                break;
            } else {
                throw new InvalidArgumentException(sprintf('Indentation problem at line %d (%s)', $this->getRealCurrentLineNb() + 1, $this->currentLine));
            }
        }
        return implode("\n", $data);
    }
    protected function moveToNextLine()
    {
        if ($this->currentLineNb >= count($this->lines) - 1) {
            return false;
        }
        $this->currentLine = $this->lines[++$this->currentLineNb];
        return true;
    }
    protected function moveToPreviousLine()
    {
        $this->currentLine = $this->lines[--$this->currentLineNb];
    }
    protected function parseValue($value)
    {
        if ('*' === substr($value, 0, 1)) {
            if (false !== $pos = strpos($value, '#')) {
                $value = substr($value, 1, $pos - 2);
            } else {
                $value = substr($value, 1);
            }
            if (!array_key_exists($value, $this->refs)) {
                throw new InvalidArgumentException(sprintf('Reference "%s" does not exist (%s).', $value, $this->currentLine));
            }
            return $this->refs[$value];
        }
        if (preg_match('/^(?P<separator>\||>)(?P<modifiers>\+|\-|\d+|\+\d+|\-\d+|\d+\+|\d+\-)?(?P<comments> +#.*)?$/', $value, $matches)) {
            $modifiers = isset($matches['modifiers']) ? $matches['modifiers'] : '';
            return $this->parseFoldedScalar($matches['separator'], preg_replace('#\d+#', '', $modifiers), intval(abs($modifiers)));
        } else {
            return sfYMLInline::load($value);
        }
    }
    protected function parseFoldedScalar($separator, $indicator = '', $indentation = 0)
    {
        $separator = '|' == $separator ? "\n" : ' ';
        $text      = '';
        $notEOF    = $this->moveToNextLine();
        while ($notEOF && $this->isCurrentLineBlank()) {
            $text .= "\n";
            $notEOF = $this->moveToNextLine();
        }
        if (!$notEOF) {
            return '';
        }
        if (!preg_match('#^(?P<indent>' . ($indentation ? str_repeat(' ', $indentation) : ' +') . ')(?P<text>.*)$#u', $this->currentLine, $matches)) {
            $this->moveToPreviousLine();
            return '';
        }
        $textIndent     = $matches['indent'];
        $previousIndent = 0;
        $text .= $matches['text'] . $separator;
        while ($this->currentLineNb + 1 < count($this->lines)) {
            $this->moveToNextLine();
            if (preg_match('#^(?P<indent> {' . strlen($textIndent) . ',})(?P<text>.+)$#u', $this->currentLine, $matches)) {
                if (' ' == $separator && $previousIndent != $matches['indent']) {
                    $text = substr($text, 0, -1) . "\n";
                }
                $previousIndent = $matches['indent'];
                $text .= str_repeat(' ', $diff = strlen($matches['indent']) - strlen($textIndent)) . $matches['text'] . ($diff ? "\n" : $separator);
            } else if (preg_match('#^(?P<text> *)$#', $this->currentLine, $matches)) {
                $text .= preg_replace('#^ {1,' . strlen($textIndent) . '}#', '', $matches['text']) . "\n";
            } else {
                $this->moveToPreviousLine();
                break;
            }
        }
        if (' ' == $separator) {
            $text = preg_replace('/ (\n*)$/', "\n$1", $text);
        }
        switch ($indicator) {
            case '':
                $text = preg_replace('#\n+$#s', "\n", $text);
                break;
            case '+':
                break;
            case '-':
                $text = preg_replace('#\n+$#s', '', $text);
                break;
        }
        return $text;
    }
    protected function isNextLineIndented()
    {
        $currentIndentation = $this->getCurrentLineIndentation();
        $notEOF             = $this->moveToNextLine();
        while ($notEOF && $this->isCurrentLineEmpty()) {
            $notEOF = $this->moveToNextLine();
        }
        if (false === $notEOF) {
            return false;
        }
        $ret = false;
        if ($this->getCurrentLineIndentation() <= $currentIndentation) {
            $ret = true;
        }
        $this->moveToPreviousLine();
        return $ret;
    }
    protected function isCurrentLineEmpty()
    {
        return $this->isCurrentLineBlank() || $this->isCurrentLineComment();
    }
    protected function isCurrentLineBlank()
    {
        return '' == trim($this->currentLine, ' ');
    }
    protected function isCurrentLineComment()
    {
        $ltrimmedLine = ltrim($this->currentLine, ' ');
        return $ltrimmedLine[0] === '#';
    }
    protected function cleanup($value)
    {
        $value = str_replace(array(
            "\r\n",
            "\r"
        ), "\n", $value);
        if (!preg_match("#\n$#", $value)) {
            $value .= "\n";
        }
        $count = 0;
        $value = preg_replace('#^\%YAML[: ][\d\.]+.*\n#su', '', $value, -1, $count);
        $this->offset += $count;
        $trimmedValue = preg_replace('#^(\#.*?\n)+#s', '', $value, -1, $count);
        if ($count == 1) {
            $this->offset += substr_count($value, "\n") - substr_count($trimmedValue, "\n");
            $value = $trimmedValue;
        }
        $trimmedValue = preg_replace('#^\-\-\-.*?\n#s', '', $value, -1, $count);
        if ($count == 1) {
            $this->offset += substr_count($value, "\n") - substr_count($trimmedValue, "\n");
            $value = $trimmedValue;
            $value = preg_replace('#\.\.\.\s*$#s', '', $value);
        }
        return $value;
    }
}
class sfYMLInline
{
    const REGEX_QUOTED_STRING = '(?:"([^"\\\\]*(?:\\\\.[^"\\\\]*)*)"|\'([^\']*(?:\'\'[^\']*)*)\')';
    static public function load($value)
    {
        $value = trim($value);
        if (0 == strlen($value)) {
            return '';
        }
        if (function_exists('mb_internal_encoding') && ((int) ini_get('mbstring.func_overload')) & 2) {
            $mbEncoding = mb_internal_encoding();
            mb_internal_encoding('ASCII');
        }
        switch ($value[0]) {
            case '[':
                $result = self::parseSequence($value);
                break;
            case '{':
                $result = self::parseMapping($value);
                break;
            default:
                $result = self::parseScalar($value);
        }
        if (isset($mbEncoding)) {
            mb_internal_encoding($mbEncoding);
        }
        return $result;
    }
    static public function dump($value)
    {
        if ('1.1' === sfYML::getSpecVersion()) {
            $trueValues  = array(
                'true',
                'on',
                '+',
                'yes',
                'y'
            );
            $falseValues = array(
                'false',
                'off',
                '-',
                'no',
                'n'
            );
        } else {
            $trueValues  = array(
                'true'
            );
            $falseValues = array(
                'false'
            );
        }
        switch (true) {
            case is_resource($value):
                throw new InvalidArgumentException('Unable to dump PHP resources in a YAML file.');
            case is_object($value):
                return '!!php/object:' . serialize($value);
            case is_array($value):
                return self::dumpArray($value);
            case null === $value:
                return 'null';
            case true === $value:
                return 'true';
            case false === $value:
                return 'false';
            case ctype_digit($value):
                return is_string($value) ? "'$value'" : (int) $value;
            case is_numeric($value):
                return is_infinite($value) ? str_ireplace('INF', '.Inf', strval($value)) : (is_string($value) ? "'$value'" : $value);
            case false !== strpos($value, "\n") || false !== strpos($value, "\r"):
                return sprintf('"%s"', str_replace(array(
                    '"',
                    "\n",
                    "\r"
                ), array(
                    '\\"',
                    '\n',
                    '\r'
                ), $value));
            case preg_match('/[ \s \' " \: \{ \} \[ \] , & \* \# \?] | \A[ - ? | < > = ! % @ ` ]/x', $value):
                return sprintf("'%s'", str_replace('\'', '\'\'', $value));
            case '' == $value:
                return "''";
            case preg_match(self::getTimestampRegex(), $value):
                return "'$value'";
            case in_array(strtolower($value), $trueValues):
                return "'$value'";
            case in_array(strtolower($value), $falseValues):
                return "'$value'";
            case in_array(strtolower($value), array(
                    'null',
                    '~'
                )):
                return "'$value'";
            default:
                return $value;
        }
    }
    static protected function dumpArray($value)
    {
        $keys = array_keys($value);
        if ((1 == count($keys) && '0' == $keys[0]) || (count($keys) > 1 && array_reduce($keys, create_function('$v,$w', 'return (integer) $v + $w;'), 0) == count($keys) * (count($keys) - 1) / 2)) {
            $output = array();
            foreach ($value as $val) {
                $output[] = self::dump($val);
            }
            return sprintf('[%s]', implode(', ', $output));
        }
        $output = array();
        foreach ($value as $key => $val) {
            $output[] = sprintf('%s: %s', self::dump($key), self::dump($val));
        }
        return sprintf('{ %s }', implode(', ', $output));
    }
    static public function parseScalar($scalar, $delimiters = null, $stringDelimiters = array('"', "'"), &$i = 0, $evaluate = true)
    {
        if (in_array($scalar[$i], $stringDelimiters)) {
            $output = self::parseQuotedScalar($scalar, $i);
        } else {
            if (!$delimiters) {
                $output = substr($scalar, $i);
                $i += strlen($output);
                if (false !== $strpos = strpos($output, ' #')) {
                    $output = rtrim(substr($output, 0, $strpos));
                }
            } else if (preg_match('/^(.+?)(' . implode('|', $delimiters) . ')/', substr($scalar, $i), $match)) {
                $output = $match[1];
                $i += strlen($output);
            } else {
                throw new InvalidArgumentException(sprintf('Malformed inline YAML string (%s).', $scalar));
            }
            $output = $evaluate ? self::evaluateScalar($output) : $output;
        }
        return $output;
    }
    static protected function parseQuotedScalar($scalar, &$i)
    {
        if (!preg_match('/' . self::REGEX_QUOTED_STRING . '/Au', substr($scalar, $i), $match)) {
            throw new InvalidArgumentException(sprintf('Malformed inline YAML string (%s).', substr($scalar, $i)));
        }
        $output = substr($match[0], 1, strlen($match[0]) - 2);
        if ('"' == $scalar[$i]) {
            $output = str_replace(array(
                '\\"',
                '\\n',
                '\\r'
            ), array(
                '"',
                "\n",
                "\r"
            ), $output);
        } else {
            $output = str_replace('\'\'', '\'', $output);
        }
        $i += strlen($match[0]);
        return $output;
    }
    static protected function parseSequence($sequence, &$i = 0)
    {
        $output = array();
        $len    = strlen($sequence);
        $i += 1;
        while ($i < $len) {
            switch ($sequence[$i]) {
                case '[':
                    $output[] = self::parseSequence($sequence, $i);
                    break;
                case '{':
                    $output[] = self::parseMapping($sequence, $i);
                    break;
                case ']':
                    return $output;
                case ',':
                case ' ':
                    break;
                default:
                    $isQuoted = in_array($sequence[$i], array(
                        '"',
                        "'"
                    ));
                    $value    = self::parseScalar($sequence, array(
                        ',',
                        ']'
                    ), array(
                        '"',
                        "'"
                    ), $i);
                    if (!$isQuoted && false !== strpos($value, ': ')) {
                        try {
                            $value = self::parseMapping('{' . $value . '}');
                        }
                        catch (InvalidArgumentException $e) {
                        }
                    }
                    $output[] = $value;
                    --$i;
            }
            ++$i;
        }
        throw new InvalidArgumentException(sprintf('Malformed inline YAML string %s', $sequence));
    }
    static protected function parseMapping($mapping, &$i = 0)
    {
        $output = array();
        $len    = strlen($mapping);
        $i += 1;
        while ($i < $len) {
            switch ($mapping[$i]) {
                case ' ':
                case ',':
                    ++$i;
                    continue 2;
                case '}':
                    return $output;
            }
            $key  = self::parseScalar($mapping, array(
                ':',
                ' '
            ), array(
                '"',
                "'"
            ), $i, false);
            $done = false;
            while ($i < $len) {
                switch ($mapping[$i]) {
                    case '[':
                        $output[$key] = self::parseSequence($mapping, $i);
                        $done         = true;
                        break;
                    case '{':
                        $output[$key] = self::parseMapping($mapping, $i);
                        $done         = true;
                        break;
                    case ':':
                    case ' ':
                        break;
                    default:
                        $output[$key] = self::parseScalar($mapping, array(
                            ',',
                            '}'
                        ), array(
                            '"',
                            "'"
                        ), $i);
                        $done         = true;
                        --$i;
                }
                ++$i;
                if ($done) {
                    continue 2;
                }
            }
        }
        throw new InvalidArgumentException(sprintf('Malformed inline YAML string %s', $mapping));
    }
    static protected function evaluateScalar($scalar)
    {
        $scalar = trim($scalar);
        if ('1.1' === sfYML::getSpecVersion()) {
            $trueValues  = array(
                'true',
                'on',
                '+',
                'yes',
                'y'
            );
            $falseValues = array(
                'false',
                'off',
                '-',
                'no',
                'n'
            );
        } else {
            $trueValues  = array(
                'true'
            );
            $falseValues = array(
                'false'
            );
        }
        switch (true) {
            case 'null' == strtolower($scalar):
            case '' == $scalar:
            case '~' == $scalar:
                return null;
            case 0 === strpos($scalar, '!str'):
                return (string) substr($scalar, 5);
            case 0 === strpos($scalar, '! '):
                return intval(self::parseScalar(substr($scalar, 2)));
            case 0 === strpos($scalar, '!!php/object:'):
                return unserialize(substr($scalar, 13));
            case ctype_digit($scalar):
                $raw  = $scalar;
                $cast = intval($scalar);
                return '0' == $scalar[0] ? octdec($scalar) : (((string) $raw == (string) $cast) ? $cast : $raw);
            case in_array(strtolower($scalar), $trueValues):
                return true;
            case in_array(strtolower($scalar), $falseValues):
                return false;
            case is_numeric($scalar):
                return '0x' == $scalar[0] . $scalar[1] ? hexdec($scalar) : floatval($scalar);
            case 0 == strcasecmp($scalar, '.inf'):
            case 0 == strcasecmp($scalar, '.NaN'):
                return -log(0);
            case 0 == strcasecmp($scalar, '-.inf'):
                return log(0);
            case preg_match('/^(-|\+)?[0-9,]+(\.[0-9]+)?$/', $scalar):
                return floatval(str_replace(',', '', $scalar));
            case preg_match(self::getTimestampRegex(), $scalar):
                return strtotime($scalar);
            default:
                return (string) $scalar;
        }
    }
    static protected function getTimestampRegex()
    {
        return <<<EOF
    ~^
    (?P<year>[0-9][0-9][0-9][0-9])
    -(?P<month>[0-9][0-9]?)
    -(?P<day>[0-9][0-9]?)
    (?:(?:[Tt]|[ \t]+)
    (?P<hour>[0-9][0-9]?)
    :(?P<minute>[0-9][0-9])
    :(?P<second>[0-9][0-9])
    (?:\.(?P<fraction>[0-9]*))?
    (?:[ \t]*(?P<tz>Z|(?P<tz_sign>[-+])(?P<tz_hour>[0-9][0-9]?)
    (?::(?P<tz_minute>[0-9][0-9]))?))?)?
    $~x
EOF;
    }
}




/*
 * This file is part of the symfony package.
 * (c) 2004-2006 Fabien Potencier <fabien.potencier@symfony-project.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/**
 * sfYML offers convenience methods to load and dump YAML.
 *
 * @package    symfony
 * @subpackage yaml
 * @author     Fabien Potencier <fabien.potencier@symfony-project.com>
 * @version    SVN: $Id: sfYML.class.php 8988 2008-05-15 20:24:26Z fabien $
 */
class sfYML
{
  static protected
    $spec = '1.2';

  /**
   * Sets the YAML specification version to use.
   *
   * @param string $version The YAML specification version
   */
  static public function setSpecVersion($version)
  {
    if (!in_array($version, array('1.1', '1.2')))
    {
      throw new InvalidArgumentException(sprintf('Version %s of the YAML specifications is not supported', $version));
    }

    self::$spec = $version;
  }

  /**
   * Gets the YAML specification version to use.
   *
   * @return string The YAML specification version
   */
  static public function getSpecVersion()
  {
    return self::$spec;
  }

  /**
   * Loads YAML into a PHP array.
   *
   * The load method, when supplied with a YAML stream (string or file),
   * will do its best to convert YAML in a file into a PHP array.
   *
   *  Usage:
   *  <code>
   *   $array = sfYML::load('config.yml');
   *   print_r($array);
   *  </code>
   *
   * @param string $input Path of YAML file or string containing YAML
   *
   * @return array The YAML converted to a PHP array
   *
   * @throws InvalidArgumentException If the YAML is not valid
   */
  public static function load($input)
  {
    $file = '';

    // if input is a file, process it
    if (strpos($input, "\n") === false && is_file($input))
    {
      $file = $input;

      ob_start();
      $retval = include($input);
      $content = ob_get_clean();

      // if an array is returned by the config file assume it's in plain php form else in YAML
      $input = is_array($retval) ? $retval : $content;
    }

    // if an array is returned by the config file assume it's in plain php form else in YAML
    if (is_array($input))
    {
      return $input;
    }

    //require_once dirname(__FILE__).'/sfYMLParser.php';

    $yaml = new sfYMLParser();

    try
    {
      $ret = $yaml->parse($input);
    }
    catch (Exception $e)
    {
      throw new InvalidArgumentException(sprintf('Unable to parse %s: %s', $file ? sprintf('file "%s"', $file) : 'string', $e->getMessage()));
    }

    return $ret;
  }

  /**
   * Dumps a PHP array to a YAML string.
   *
   * The dump method, when supplied with an array, will do its best
   * to convert the array into friendly YAML.
   *
   * @param array   $array PHP array
   * @param integer $inline The level where you switch to inline YAML
   *
   * @return string A YAML string representing the original PHP array
   */
  public static function dump($array, $inline = 2)
  {
//    require_once dirname(__FILE__).'/sfYMLDumper.php';

    $yaml = new sfYMLDumper();

    return $yaml->dump($array, $inline);
  }
}


