<?php
/**
 * Redirect plugin
 *
 * @license    GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author     Andreas Gohr <andi@splitbrain.org>
 */

// must be run within Dokuwiki
if(!defined('DOKU_INC')) die();

/**
 * Class helper_plugin_redirect
 *
 * Save and load the config file
 */
class helper_plugin_redirect extends DokuWiki_Admin_Plugin {

    const CONFIG_FILE = DOKU_CONF . '/redirect.conf';
    const LEGACY_FILE = __DIR__ . '/redirect.conf';

    /**
     * @var string[] Redirections from config file
     */
    protected $redirects;

    /**
     * @var array Regex-based redirections
     */
    protected $regexRedirects = [];

    /**
     * helper_plugin_redirect constructor.
     *
     * handles the legacy file
     */
    public function __construct() {
        // move config from plugin directory to conf directory
        if(!file_exists(self::CONFIG_FILE) &&
            file_exists(self::LEGACY_FILE)) {
            rename(self::LEGACY_FILE, self::CONFIG_FILE);
        }
    }

    /**
     * Saves the config file
     *
     * @param string $config the raw text for the config
     * @return bool
     */
    public function saveConfigFile($config) {
        $config = cleanText($config);

        // Check regex patterns, report invalid ones
        $this->redirects = linesToHash(explode("\n", $config));
        $invalid = $this->checkInvalidPatterns();

        if ($invalid) {
            msg(sprintf($this->getLang('invalid'), implode('<br>', $invalid)), 2);
        }

        return io_saveFile(self::CONFIG_FILE, $config);
    }

    /**
     * Load the config file
     *
     * @return string the raw text of the config
     */
    public function loadConfigFile() {
        if(!file_exists(self::CONFIG_FILE)) return '';
        return io_readFile(self::CONFIG_FILE);
    }

    /**
     * Get the redirect URL for a given ID
     *
     * Handles conf['showmsg']
     *
     * @param string $id the ID for which the redirect is wanted
     * @return bool|string the full URL to redirect to
     */
    public function getRedirectURL($id) {
        $this->redirects = confToHash(self::CONFIG_FILE);

        $redirect = $this->redirects[$id] ?? null;

        // If no "plain" redirection exists, check if we have a match with regex-based ones
        if (!$redirect) {
            foreach ($this->getRegexRedirects() as $source => $target) {
                $newId = preg_replace($source, $target, $id);
                if ($newId !== null && $newId != $id) {
                    $redirect = $newId;
                    break;
                }
            }
        }

        // No redirection found
        if (!$redirect) return false;

        if(preg_match('/^https?:\/\//', $redirect)) {
            $url = $redirect;
        } else {
            if($this->getConf('showmsg')) {
                msg(sprintf($this->getLang('redirected'), hsc($id)));
            }
            $link = explode('#', $redirect, 2);
            $url = wl($link[0], '', true, '&');
            if(isset($link[1])) $url .= '#' . rawurlencode($link[1]);
        }

        return $url;
    }

    /**
     * Dummy implementation of an abstract method
     */
    public function html()
    {
        return '';
    }

    /**
     * Check whether the given string is a Regex pattern.
     *
     * The function does not actually validate the regex; it only ensures that
     * the string is bound by valid delimiters
     * {@see https://www.php.net/manual/en/regexp.reference.delimiters.php},
     * so it can be processed by pcre_xxx functions.
     *
     * @param string $str
     * @return bool
     */
    protected function isRegex($str)
    {
        $pattern = '/^(?=([^a-zA-Z0-9\s])) # Starts with a valid delimiter
            (?|
                # bracket style: (), {}, [] and <>
                \(.*\) | \[.*] | \{.*} | <.*>
                |
                # other delimiters (back-reference)
                .+\1
            )
            # modifiers
            [imsxADSUXu]*
            $/xs';

        return preg_match($pattern, trim($str));
    }

    protected function getRegexRedirects()
    {
        if (!$this->regexRedirects) {
            foreach ($this->redirects as $source => $target) {
                if ($this->isRegex($source)) {
                    $this->regexRedirects[$source] = $target;
                    unset($this->redirects[$source]);
                }
            }
        }

        return $this->regexRedirects;
    }

    /**
     * Check for invalid Regex patterns in the redirections,
     *
     * @return string[] Formatted error messages.
     *
     * @noinspection PhpUnhandledExceptionInspection PhpDocMissingThrowsInspection
     */
    protected function checkInvalidPatterns()
    {
        // Error handler to convert PHP warnings from preg_match() to exceptions.
        set_error_handler(
            function ($errno, $errstr, $errfile, $errline) {
                throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
            },
            E_WARNING
        );

        // Check regex patterns, log invalid ones including error message
        $invalid = [];
        foreach (array_keys($this->getRegexRedirects()) as $source) {
            try {
                preg_match($source, '');
            } catch(Exception $e) {
                $invalid[] = "<code>$source</code> &ndash; " . $e->getMessage();
            }
        }

        restore_error_handler();

        return $invalid;
    }

}
