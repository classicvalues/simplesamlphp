<?php

declare(strict_types=1);

namespace SimpleSAML\Module\admin\Controller;

use SimpleSAML\Configuration;
use SimpleSAML\HTTP\RunnableResponse;
use SimpleSAML\Locale\Translate;
use SimpleSAML\Metadata\MetaDataStorageHandler;
use SimpleSAML\Module;
use SimpleSAML\Session;
use SimpleSAML\Utils;
use SimpleSAML\XHTML\Template;
use Symfony\Component\HttpFoundation\Request;

/**
 * Controller class for the admin module.
 *
 * This class serves the configuration views available in the module.
 *
 * @package SimpleSAML\Module\admin
 */
class Config
{
    public const LATEST_VERSION_STATE_KEY = 'core:latest_simplesamlphp_version';

    public const RELEASES_API = 'https://api.github.com/repos/simplesamlphp/simplesamlphp/releases/latest';

    /** @var \SimpleSAML\Configuration */
    protected Configuration $config;

    /** @var \SimpleSAML\Utils\Auth */
    protected $authUtils;

    /** @var \SimpleSAML\Utils\HTTP */
    protected $httpUtils;

    /** @var \SimpleSAML\Module\admin\Controller\Menu */
    protected Menu $menu;

    /** @var \SimpleSAML\Session */
    protected Session $session;


    /**
     * ConfigController constructor.
     *
     * @param \SimpleSAML\Configuration $config The configuration to use.
     * @param \SimpleSAML\Session $session The current user session.
     */
    public function __construct(Configuration $config, Session $session)
    {
        $this->config = $config;
        $this->session = $session;
        $this->menu = new Menu();
        $this->authUtils = new Utils\Auth();
        $this->httpUtils = new Utils\HTTP();
    }


    /**
     * Inject the \SimpleSAML\Utils\Auth dependency.
     *
     * @param \SimpleSAML\Utils\Auth $authUtils
     */
    public function setAuthUtils(Utils\Auth $authUtils): void
    {
        $this->authUtils = $authUtils;
    }


    /**
     * Display basic diagnostic information on hostname, port and protocol.
     *
     * @param \Symfony\Component\HttpFoundation\Request $request The current request.
     *
     * @return \SimpleSAML\XHTML\Template
     */
    public function diagnostics(Request $request): Template
    {
        $this->authUtils->requireAdmin();

        $t = new Template($this->config, 'admin:diagnostics.twig');
        $t->data = [
            'remaining' => $this->session->getAuthData('admin', 'Expire') - time(),
            'logouturl' => $this->authUtils->getAdminLogoutURL(),
            'items' => [
                'HTTP_HOST' => [$request->getHost()],
                'HTTPS' => $request->isSecure() ? ['on'] : [],
                'SERVER_PROTOCOL' => [$request->getProtocolVersion()],
                'getBaseURL()' => [$this->httpUtils->getBaseURL()],
                'getSelfHost()' => [$this->httpUtils->getSelfHost()],
                'getSelfHostWithNonStandardPort()' => [$this->httpUtils->getSelfHostWithNonStandardPort()],
                'getSelfURLHost()' => [$this->httpUtils->getSelfURLHost()],
                'getSelfURLNoQuery()' => [$this->httpUtils->getSelfURLNoQuery()],
                'getSelfHostWithPath()' => [$this->httpUtils->getSelfHostWithPath()],
                'getFirstPathElement()' => [$this->httpUtils->getFirstPathElement()],
                'getSelfURL()' => [$this->httpUtils->getSelfURL()],
            ],
        ];

        $this->menu->addOption('logout', $t->data['logouturl'], Translate::noop('Log out'));
        return $this->menu->insert($t);
    }


    /**
     * Display the main admin page.
     *
     * @param \Symfony\Component\HttpFoundation\Request $request The current request.
     *
     * @return \SimpleSAML\XHTML\Template
     */
    public function main(/** @scrutinizer ignore-unused */ Request $request): Template
    {
        $this->authUtils->requireAdmin();

        $t = new Template($this->config, 'admin:config.twig');
        $t->data = [
            'warnings' => $this->getWarnings(),
            'directory' => $this->config->getBaseDir(),
            'version' => $this->config->getVersion(),
            'links' => [
                [
                    'href' => Module::getModuleURL('admin/diagnostics'),
                    'text' => Translate::noop('Diagnostics on hostname, port and protocol')
                ],
                [
                    'href' => Module::getModuleURL('admin/phpinfo'),
                    'text' => Translate::noop('Information on your PHP installation')
                ]
            ],
            'enablematrix' => [
                'saml20idp' => $this->config->getBoolean('enable.saml20-idp', false),
            ],
            'funcmatrix' => $this->getPrerequisiteChecks(),
            'logouturl' => $this->authUtils->getAdminLogoutURL(),
        ];

        Module::callHooks('configpage', $t);
        $this->menu->addOption('logout', $this->authUtils->getAdminLogoutURL(), Translate::noop('Log out'));
        return $this->menu->insert($t);
    }


    /**
     * Display the output of phpinfo().
     *
     * @param \Symfony\Component\HttpFoundation\Request $request The current request.
     *
     * @return \SimpleSAML\HTTP\RunnableResponse
     */
    public function phpinfo(/** @scrutinizer ignore-unused */ Request $request): RunnableResponse
    {
        $this->authUtils->requireAdmin();

        return new RunnableResponse('phpinfo');
    }


    /**
     * Perform a list of checks on the current installation, and return the results as an array.
     *
     * The elements in the array returned are also arrays with the following keys:
     *
     *   - required: Whether this prerequisite is mandatory or not. One of "required" or "optional".
     *   - descr: A translatable text that describes the prerequisite. If the text uses parameters, the value must be an
     *     array where the first value is the text to translate, and the second is a hashed array containing the
     *     parameters needed to properly translate the text.
     *   - enabled: True if the prerequisite is met, false otherwise.
     *
     * @return array
     */
    protected function getPrerequisiteChecks(): array
    {
        $matrix = [
            [
                'required' => 'required',
                'descr' => [
                    Translate::noop('PHP %minimum% or newer is needed. You are running: %current%'),
                    [
                        '%minimum%' => '7.4',
                        '%current%' => explode('-', phpversion())[0]
                    ]
                ],
                'enabled' => version_compare(phpversion(), '7.4', '>=')
            ]
        ];
        $store = $this->config->getString('store.type', '');

        // check dependencies used via normal functions
        $functions = [
            'time' => [
                'required' => 'required',
                'descr' => [
                    'required' => Translate::noop('Date/Time Extension'),
                ]
            ],
            'hash' => [
                'required' => 'required',
                'descr' => [
                    'required' => Translate::noop('Hashing function'),
                ]
            ],
            'gzinflate' => [
                'required' => 'required',
                'descr' => [
                    'required' => Translate::noop('ZLib'),
                ]
            ],
            'openssl_sign' => [
                'required' => 'required',
                'descr' => [
                    'required' => Translate::noop('OpenSSL'),
                ]
            ],
            'dom_import_simplexml' => [
                'required' => 'required',
                'descr' => [
                    'required' => Translate::noop('XML DOM'),
                ]
            ],
            'preg_match' => [
                'required' => 'required',
                'descr' => [
                    'required' => Translate::noop('Regular expression support'),
                ]
            ],
            'json_decode' => [
                'required' => 'required',
                'descr' => [
                    'required' => Translate::noop('JSON support'),
                ]
            ],
            'class_implements' => [
                'required' => 'required',
                'descr' => [
                    'required' => Translate::noop('Standard PHP library (SPL)'),
                ]
            ],
            'mb_strlen' => [
                'required' => 'required',
                'descr' => [
                    'required' => Translate::noop('Multibyte String extension'),
                ]
            ],
            'curl_init' => [
                'required' => $this->config->getBoolean('admin.checkforupdates', true) ? 'required' : 'optional',
                'descr' => [
                    'optional' => Translate::noop(
                        'cURL (might be required by some modules)'
                    ),
                    'required' => Translate::noop(
                        'cURL (required if automatic version checks are used, also by some modules)'
                    ),
                ]
            ],
            'session_start' => [
                'required' => $store === 'phpsession' ? 'required' : 'optional',
                'descr' => [
                    'optional' => Translate::noop('Session extension (required if PHP sessions are used)'),
                    'required' => Translate::noop('Session extension'),
                ]
            ],
            'pdo_drivers' => [
                'required' => $store === 'sql' ? 'required' : 'optional',
                'descr' => [
                    'optional' => Translate::noop('PDO Extension (required if a database backend is used)'),
                    'required' => Translate::noop('PDO extension'),
                ]
            ],
            'ldap_bind' => [
                'required' => Module::isModuleEnabled('ldap') ? 'required' : 'optional',
                'descr' => [
                    'optional' => Translate::noop('LDAP extension (required if an LDAP backend is used)'),
                    'required' => Translate::noop('LDAP extension'),
                ]
            ],
            'radius_auth_open' => [
                'required' => Module::isModuleEnabled('radius') ? 'required' : 'optional',
                'descr' => [
                    'optional' => Translate::noop('Radius extension (required if a radius backend is used)'),
                    'required' => Translate::noop('Radius extension'),
                ]
            ],
        ];

        foreach ($functions as $function => $description) {
            $matrix[] = [
                'required' => $description['required'],
                'descr' => $description['descr'][$description['required']],
                'enabled' => function_exists($function),
            ];
        }

        // check object-oriented external libraries and extensions
        $libs = [
            [
                'classes' => ['\Predis\Predis'],
                'required' => $store === 'redis' ? 'required' : 'optional',
                'descr' => [
                    'optional' => Translate::noop('predis/predis (required if the redis data store is used)'),
                    'required' => Translate::noop('predis/predis library'),
                ]
            ],
            [
                'classes' => ['\Memcache', '\Memcached'],
                'required' => $store === 'memcache' ? 'required' : 'optional',
                'descr' => [
                    'optional' => Translate::noop(
                        'Memcache or Memcached extension (required if the memcache backend is used)'
                    ),
                    'required' => Translate::noop('Memcache or Memcached extension'),
                ]
            ]
        ];

        foreach ($libs as $lib) {
            $enabled = false;
            foreach ($lib['classes'] as $class) {
                $enabled |= class_exists($class);
            }
            $matrix[] = [
                'required' => $lib['required'],
                'descr' => $lib['descr'][$lib['required']],
                'enabled' => $enabled,
            ];
        }

        // perform some basic configuration checks
        $matrix[] = [
            'required' => 'optional',
            'descr' => Translate::noop('The <code>technicalcontact_email</code> configuration option should be set'),
            'enabled' => $this->config->getString('technicalcontact_email', 'na@example.org') !== 'na@example.org',
        ];

        $matrix[] = [
            'required' => 'required',
            'descr' => Translate::noop('The auth.adminpassword configuration option must be set'),
            'enabled' => $this->config->getString('auth.adminpassword', '123') !== '123',
        ];

        $cryptoUtils = new Utils\Crypto();

        // perform some sanity checks on the configured certificates
        if ($this->config->getBoolean('enable.saml20-idp', false) !== false) {
            $handler = MetaDataStorageHandler::getMetadataHandler();
            try {
                $metadata = $handler->getMetaDataCurrent('saml20-idp-hosted');
            } catch (\Exception $e) {
                 $matrix[] = [
                     'required' => 'required',
                     'descr' => Translate::noop('Hosted IdP metadata present'),
                     'enabled'=>false
                 ];
            }

            if(isset($metadata)) {
                $metadata_config = Configuration::loadfromArray($metadata);
                $private = $cryptoUtils->loadPrivateKey($metadata_config, false);
                $public = $cryptoUtils->loadPublicKey($metadata_config, false);

                $matrix[] = [
                    'required' => 'required',
                    'descr' => Translate::noop('Matching key-pair for signing assertions'),
                    'enabled' => $this->matchingKeyPair($public['PEM'], $private['PEM'], $private['password']),
                ];

                $private = $cryptoUtils->loadPrivateKey($metadata_config, false, 'new_');
                if ($private !== null) {
                    $public = $cryptoUtils->loadPublicKey($metadata_config, false, 'new_');
                    $matrix[] = [
                        'required' => 'required',
                        'descr' => Translate::noop('Matching key-pair for signing assertions (rollover key)'),
                        'enabled' => $this->matchingKeyPair($public['PEM'], $private['PEM'], $private['password']),
                    ];
                }
            }
        }

        if ($this->config->getBoolean('metadata.sign.enable', false) !== false) {
            $private = $cryptoUtils->loadPrivateKey($this->config, false, 'metadata.sign.');
            $public = $cryptoUtils->loadPublicKey($this->config, false, 'metadata.sign.');
            $matrix[] = [
                'required' => 'required',
                'descr' => Translate::noop('Matching key-pair for signing metadata'),
                'enabled' => $this->matchingKeyPair($public['PEM'], $private['PEM'], $private['password']),
            ];

        }

        return $matrix;
    }


    /**
     * Compile a list of warnings about the current deployment.
     *
     * The returned array can contain either strings that can be translated directly, or arrays. If an element is an
     * array, the first value in that array is a string that can be translated, and the second value will be a hashed
     * array that contains the substitutions that must be applied to the translation, with its corresponding value. This
     * can be used in twig like this, assuming an element called "e":
     *
     *     {{ e[0]|trans(e[1])|raw }}
     *
     * @return array
     */
    protected function getWarnings(): array
    {
        $warnings = [];

        // make sure we're using HTTPS
        if (!$this->httpUtils->isHTTPS()) {
            $warnings[] = Translate::noop(
                '<strong>You are not using HTTPS</strong> to protect communications with your users. HTTP works fine ' .
                'for testing purposes, but in a production environment you should use HTTPS. <a ' .
                'href="https://simplesamlphp.org/docs/stable/simplesamlphp-maintenance">Read more about the ' .
                'maintenance of SimpleSAMLphp</a>.'
            );
        }

        // make sure we have a secret salt set
        if ($this->config->getValue('secretsalt') === 'defaultsecretsalt') {
            $warnings[] = Translate::noop(
                '<strong>The configuration uses the default secret salt</strong>. Make sure to modify the <code>' .
                'secretsalt</code> option in the SimpleSAMLphp configuration in production environments. <a ' .
                'href="https://simplesamlphp.org/docs/stable/simplesamlphp-install">Read more about the ' .
                'maintenance of SimpleSAMLphp</a>.'
            );
        }

        // check for URL limitations
        if (extension_loaded('suhosin')) {
            $len = ini_get('suhosin.get.max_value_length');
            if (empty($len) || $len < 2048) {
                $warnings[] = Translate::noop(
                    'The length of query parameters is limited by the PHP Suhosin extension. Please increase the ' .
                    '<code>suhosin.get.max_value_length</code> option in your php.ini to at least 2048 bytes.'
                );
            }
        }

        /*
         * Check for updates. Store the remote result in the session so we don't need to fetch it on every access to
         * this page.
         */
        if ($this->config->getBoolean('admin.checkforupdates', true) && $this->config->getVersion() !== 'master') {
            if (!function_exists('curl_init')) {
                $warnings[] = Translate::noop(
                    'The cURL PHP extension is missing. Cannot check for SimpleSAMLphp updates.'
                );
            } else {
                $latest = $this->session->getData(self::LATEST_VERSION_STATE_KEY, "version");

                if (!$latest) {
                    $ch = curl_init(self::RELEASES_API);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                    curl_setopt($ch, CURLOPT_USERAGENT, 'SimpleSAMLphp');
                    curl_setopt($ch, CURLOPT_TIMEOUT, 2);
                    curl_setopt($ch, CURLOPT_PROXY, $this->config->getString('proxy', null));
                    curl_setopt($ch, CURLOPT_PROXYUSERPWD, $this->config->getValue('proxy.auth', null));
                    $response = curl_exec($ch);

                    if (curl_getinfo($ch, CURLINFO_RESPONSE_CODE) === 200) {
                        /** @psalm-var string $response */
                        $latest = json_decode($response, true);
                        $this->session->setData(self::LATEST_VERSION_STATE_KEY, 'version', $latest);
                    }
                    curl_close($ch);
                }

                if ($latest && version_compare($this->config->getVersion(), ltrim($latest['tag_name'], 'v'), 'lt')) {
                    $warnings[] = [
                        Translate::noop(
                            'You are running an outdated version of SimpleSAMLphp. Please update to <a href="' .
                            '%latest%">the latest version</a> as soon as possible.'
                        ),
                            [
                                '%latest%' => $latest['html_url']
                            ]
                    ];
                }
            }
        }

        return $warnings;
    }


    /**
     * Test whether public & private key are a matching pair
     *
     * @param string $publicKey
     * @param string $privateKey
     * @param string|null $password
     * @return bool
     */
    private function matchingKeyPair(string $publicKey, string $privateKey, ?string $password = null) : bool {
        return openssl_x509_check_private_key($publicKey, [$privateKey, $password]);
    }
}
