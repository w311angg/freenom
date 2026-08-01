<?php
/**
 * FreeNom域名自动续期
 *
 * @author mybsdc <mybsdc@gmail.com>
 * @date 2020/1/19
 * @time 17:29
 * @link https://github.com/luolongfei/freenom
 */

namespace Luolongfei\App\Console;

use Luolongfei\App\Constants\CommonConst;
use Luolongfei\App\Exceptions\LlfException;
use Luolongfei\App\Exceptions\WarningException;
use GuzzleHttp\Client;
use GuzzleHttp\Pool;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Exception\RequestException;
use Luolongfei\Libs\Log;
use Luolongfei\Libs\Message;
use GuzzleHttp\Cookie\SetCookie;

class FreeNom extends Base
{
    const VERSION = 'v0.6.2';

    const TIMEOUT = 60;

    // FreeNom登录地址
    const LOGIN_URL = 'https://my.freenom.com/dologin.php';

    // 域名续期状态地址：读取 token、域名、剩余天数
    const DOMAIN_STATUS_URL = 'https://my.freenom.com/domains.php?a=renewals';

    // 域名列表地址：新版页面从这里读取 domain id
    const DOMAIN_LIST_URL = 'https://my.freenom.com/clientarea.php?action=domains';

    // 域名续期地址
    const RENEW_DOMAIN_URL = 'https://my.freenom.com/domains.php?submitrenewals=true';

    // 免费域名只允许在到期前 14 天内续期
    const RENEW_BEFORE_DAYS = 14;

    // 续期请求最大并发数
    const RENEW_CONCURRENCY = 10;

    // 匹配 token 的正则
    const TOKEN_REGEX = '/<input\b[^>]*\bname=["\']token["\'][^>]*\bvalue=["\'](?P<token>[^"\']+)/i';

    // HTML 解析用正则
    const DOMAIN_ROW_REGEX = '/<tr\b[^>]*>(?P<row>.*?)<\/tr>/is';
    const DOMAIN_CELL_REGEX = '/<td\b[^>]*>(?P<cell>.*?)<\/td>/is';
    const DOMAIN_NAME_REGEX = '/\b(?P<domain>[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.(?:tk|ml|ga|cf|gq))\b/i';
    const DOMAIN_ID_REGEX = '/(?:action=domaindetails[^"\'<>]*(?:&amp;|&)id=|renewalperiod\[|(?:[?&]|&amp;)(?:id|domainid|domain|renewalid)=)(?P<id>\d+)/i';
    const DOMAIN_DAYS_REGEX = '/(?P<days>\d+)\s*(?:Days?|天)/i';

    // 匹配登录状态的正则
    const LOGIN_STATUS_REGEX = '/<li.*?Logout.*?<\/li>/i';

    // 匹配无域名的正则
    const NO_DOMAIN_REGEX = '/<tr\sclass="carttablerow"><td\scolspan="5">(?P<msg>[^<]+)<\/td><\/tr>/i';

    /**
     * @var Client
     */
    protected $client;

    /**
     * @var CookieJar | bool
     */
    protected $jar = true;

    /**
     * @var string FreeNom 账户
     */
    protected $username;

    /**
     * @var string FreeNom 密码
     */
    protected $password;

    /**
     * @var bool 是否使用 cookies 文件登录态
     */
    protected $cookieSessionMode = false;

    /**
     * @var FreeNom
     */
    private static $instance;

    /**
     * @var int 最大请求重试次数
     */
    public $maxRequestRetryCount;

    /**
     * 命令行 cookies 文件参数名
     */
    protected const DEFAULT_COOKIE_FILE = 'cookies.txt';

    protected const COOKIE_FILE_ARG_NAMES = [
        'cookies',
        'cookie',
        'cookies_file',
        'cookie_file',
    ];

    /**
     * @return FreeNom
     */
    public static function getInstance()
    {
        if (!self::$instance instanceof self) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct()
    {
        $this->client = new Client([
            'headers' => [
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,image/apng,*/*;q=0.8,application/signed-exchange;v=b3;q=0.9',
                'Accept-Encoding' => 'gzip, deflate, br',
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/121.0.0.0 Safari/537.36',
            ],
            'timeout' => self::TIMEOUT,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_AUTOREFERER => true,
            'verify' => config('verify_ssl'),
            'debug' => config('debug'),
            'proxy' => config('freenom_proxy'),
        ]);

        $this->maxRequestRetryCount = config('max_request_retry_count');

        system_log(sprintf(lang('100038'), self::VERSION));
    }

    private function __clone()
    {
    }

    /**
     * 登录
     *
     * @param string $username
     * @param string $password
     *
     * @return bool
     * @throws LlfException
     */
    protected function login(string $username, string $password)
    {
        try {
            autoRetry(function ($username, $password, &$jar) {
                return $this->client->post(self::LOGIN_URL, [
                    'headers' => [
                        'Content-Type' => 'application/x-www-form-urlencoded',
                        'Referer' => 'https://my.freenom.com/clientarea.php'
                    ],
                    'form_params' => [
                        'username' => $username,
                        'password' => $password
                    ],
                    'cookies' => $jar
                ]);
            }, $this->maxRequestRetryCount, [$username, $password, &$this->jar]);
        } catch (\Exception $e) {
            throw new LlfException(34520002, $e->getMessage());
        }

        $loginCookie = $this->jar->getCookieByName('WHMCSZH5eHTGhfvzP');
        if (!$loginCookie instanceof SetCookie || $loginCookie->getValue() === '') {
            throw new LlfException(34520002, lang('100001'));
        }

        system_log(sprintf(lang('100138'), $username));

        return true;
    }

    /**
     * 获取命令行传入的 cookies 文件路径
     *
     * 支持：
     * php run
     * php run cookies.json
     * php run --cookies=cookies.json
     * php run --cookie=/path/to/cookies.json
     *
     * 不传路径时，默认尝试读取项目根目录的 cookies.txt。
     * cookies.txt 为空时不启用 cookie 登录态，不影响原有账号密码登录。
     * 相对路径统一按项目根目录解析。
     *
     * @return string
     * @throws LlfException
     */
    protected function getCookieFilePath()
    {
        $path = '';

        foreach (self::COOKIE_FILE_ARG_NAMES as $argName) {
            $argValue = get_argv($argName, '');
            if ($argValue !== '') {
                $path = $argValue;

                break;
            }
        }

        if ($path === '') {
            $path = $this->getPositionalCookieFilePath();
        }

        if ($path === '') {
            $defaultCookieFilePath = ROOT_PATH . DS . self::DEFAULT_COOKIE_FILE;
            if (!is_file($defaultCookieFilePath)) {
                return '';
            }

            $contents = file_get_contents($defaultCookieFilePath);
            if ($contents === false || trim($contents) === '') {
                return '';
            }

            return $defaultCookieFilePath;
        }

        if (!is_string($path) || $path === '1') {
            throw new LlfException(34520022, 'empty cookies path');
        }

        return $this->resolveCookieFilePath($path);
    }

    /**
     * 获取第一个非选项形式的命令行参数作为 cookies 文件路径
     *
     * @return string
     */
    protected function getPositionalCookieFilePath()
    {
        if (!IS_CLI) {
            return '';
        }

        global $argv;

        if (!is_array($argv)) {
            return '';
        }

        foreach (array_slice($argv, 1) as $arg) {
            if (!is_string($arg) || $arg === '' || str_starts_with($arg, '-')) {
                continue;
            }

            return $arg;
        }

        return '';
    }

    /**
     * 解析 cookies 文件路径
     *
     * @param string $path
     *
     * @return string
     */
    protected function resolveCookieFilePath(string $path)
    {
        $path = trim($path);

        if ($path !== '' && $path[0] === '~') {
            $home = getenv('HOME');
            if ($home) {
                $path = $home . substr($path, 1);
            }
        }

        if ($this->isAbsolutePath($path)) {
            return $path;
        }

        return ROOT_PATH . DS . ltrim($path, '/\\');
    }

    /**
     * 判断是否绝对路径
     *
     * @param string $path
     *
     * @return bool
     */
    protected function isAbsolutePath(string $path)
    {
        return $path !== ''
            && (
                $path[0] === '/'
                || preg_match('/^[a-zA-Z]:[\/\\\\]/', $path)
                || str_starts_with($path, '\\\\')
            );
    }

    /**
     * 从浏览器导出的 JSON cookies 文件构建 CookieJar
     *
     * @param string $filePath
     *
     * @return CookieJar
     * @throws LlfException
     */
    protected function buildCookieJarFromFile(string $filePath)
    {
        if (!is_file($filePath)) {
            throw new LlfException(34520022, $filePath);
        }

        $contents = file_get_contents($filePath);
        if ($contents === false) {
            throw new LlfException(34520023, $filePath);
        }

        $cookies = json_decode($contents, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($cookies)) {
            throw new LlfException(34520023, json_last_error_msg());
        }

        if (isset($cookies['cookies']) && is_array($cookies['cookies'])) {
            $cookies = $cookies['cookies'];
        }

        $jar = new CookieJar();

        foreach ($cookies as $cookieItem) {
            if (!is_array($cookieItem)) {
                continue;
            }

            $cookie = $this->buildCookieFromBrowserExport($cookieItem);
            if ($cookie instanceof SetCookie) {
                $jar->setCookie($cookie);
            }
        }

        if (count($jar) === 0) {
            throw new LlfException(34520024, $filePath);
        }

        system_log(sprintf(lang('100142'), count($jar), $filePath));

        return $jar;
    }

    /**
     * 将浏览器 cookies 导出项转换为 Guzzle SetCookie
     *
     * @param array $cookieItem
     *
     * @return SetCookie|null
     */
    protected function buildCookieFromBrowserExport(array $cookieItem)
    {
        if (!isset($cookieItem['name']) || (string)$cookieItem['name'] === '') {
            return null;
        }

        if (!array_key_exists('value', $cookieItem)) {
            return null;
        }

        $domain = isset($cookieItem['domain']) ? (string)$cookieItem['domain'] : 'my.freenom.com';
        if ($domain === '') {
            $domain = 'my.freenom.com';
        }

        $cookie = [
            'Name' => (string)$cookieItem['name'],
            'Value' => (string)$cookieItem['value'],
            'Domain' => $domain,
            'Path' => isset($cookieItem['path']) && (string)$cookieItem['path'] !== '' ? (string)$cookieItem['path'] : '/',
            'Secure' => !empty($cookieItem['secure']),
            'HttpOnly' => !empty($cookieItem['httpOnly']),
            'Discard' => !empty($cookieItem['session']),
        ];

        if (isset($cookieItem['expirationDate']) && !$cookie['Discard']) {
            $expires = (float)$cookieItem['expirationDate'];
            if ($expires > 9999999999) {
                $expires = $expires / 1000;
            }

            if ($expires > 0) {
                $cookie['Expires'] = (int)floor($expires);
            }
        }

        return new SetCookie($cookie);
    }

    /**
     * 请求前补充 AWS WAF token
     *
     * 仅账号密码登录模式使用；cookies 模式不处理 aws-waf-token。
     *
     * @return void
     * @throws LlfException
     */
    protected function prepareAwsWafToken()
    {
        if (needAwsWafToken()) {
            $awsWafToken = getAwsWafToken();
            $this->jar->setCookie(buildAwsWafCookie($awsWafToken));

            return;
        }

        system_log(lang('100140'));
    }

    /**
     * 将 HTML 片段转换成普通文本
     *
     * @param string $html
     *
     * @return string
     */
    protected function htmlToText(string $html)
    {
        $html = preg_replace('/<script\b[\s\S]*?<\/script>/i', ' ', $html) ?? $html;
        $html = preg_replace('/<style\b[\s\S]*?<\/style>/i', ' ', $html) ?? $html;
        $html = preg_replace('/<[^>]+>/', ' ', $html) ?? $html;
        $text = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($text);
    }

    /**
     * 从 HTML 片段中提取域名
     *
     * @param string $html
     *
     * @return string
     */
    protected function extractDomain(string $html)
    {
        $text = $this->htmlToText($html);
        if (!preg_match(self::DOMAIN_NAME_REGEX, $text, $matches)) {
            return '';
        }

        return strtolower($matches['domain']);
    }

    /**
     * 从 HTML 片段或 URL 中提取 domain id
     *
     * @param string $html
     *
     * @return string
     */
    protected function extractDomainId(string $html)
    {
        $html = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (!preg_match(self::DOMAIN_ID_REGEX, $html, $matches)) {
            return '';
        }

        return trim($matches['id']);
    }

    /**
     * 从 HTML 片段中提取剩余天数
     *
     * @param string $html
     *
     * @return int|null
     */
    protected function extractDays(string $html)
    {
        $text = $this->htmlToText($html);
        if (!preg_match(self::DOMAIN_DAYS_REGEX, $text, $matches)) {
            return null;
        }

        return (int)$matches['days'];
    }

    /**
     * 解析 My Domains 页面中的 domain => id 映射
     *
     * 新版页面的 domain id 位于 /clientarea.php?action=domains 里的详情链接中，
     * 例如：clientarea.php?action=domaindetails&id=1132358463
     *
     * @param string $domainListPage
     *
     * @return array
     * @throws LlfException
     */
    protected function getDomainIdMap(string $domainListPage)
    {
        $domainIdMap = [];

        if (preg_match_all(self::DOMAIN_ROW_REGEX, $domainListPage, $rows, PREG_SET_ORDER)) {
            foreach ($rows as $rowMatch) {
                $row = $rowMatch['row'];
                $domain = $this->extractDomain($row);
                $id = $this->extractDomainId($row);

                if ($domain !== '' && $id !== '') {
                    $domainIdMap[$domain] = $id;
                }
            }
        }

        if (empty($domainIdMap)) {
            throw new LlfException(34520003);
        }

        return $domainIdMap;
    }

    /**
     * 匹配获取所有域名
     *
     * @param string $domainStatusPage
     * @param array $domainIdMap
     *
     * @return array
     * @throws LlfException
     * @throws WarningException
     */
    protected function getAllDomains(string $domainStatusPage, array $domainIdMap = [])
    {
        if (preg_match(self::NO_DOMAIN_REGEX, $domainStatusPage, $m)) {
            throw new WarningException(34520014, [$this->username, $m['msg']]);
        }

        if (!preg_match_all(self::DOMAIN_ROW_REGEX, $domainStatusPage, $rows, PREG_SET_ORDER)) {
            throw new LlfException(34520003);
        }

        $allDomains = [];
        foreach ($rows as $rowMatch) {
            $row = $rowMatch['row'];
            if (!preg_match_all(self::DOMAIN_CELL_REGEX, $row, $cells, PREG_SET_ORDER)) {
                continue;
            }

            $domain = $this->extractDomain($cells[0]['cell'] ?? '');
            if ($domain === '') {
                continue;
            }

            $days = null;
            foreach ($cells as $cell) {
                $days = $this->extractDays($cell['cell']);
                if ($days !== null) {
                    break;
                }
            }

            if ($days === null) {
                $days = $this->extractDays($row);
            }

            if ($days === null) {
                continue;
            }

            $allDomains[] = [
                'domain' => $domain,
                'days' => (string)$days,
                // 新版页面优先从 My Domains 页面取 id；旧页面仍兼容行内 renewdomain id
                'id' => $domainIdMap[$domain] ?? $this->extractDomainId($row),
            ];
        }

        if (empty($allDomains)) {
            throw new LlfException(34520003);
        }

        return $allDomains;
    }

    /**
     * 将 My Domains 页面中的 domain id 合并到续期页域名列表
     *
     * @param array $allDomains
     * @param array $domainIdMap
     *
     * @return array
     */
    protected function fillDomainIds(array $allDomains, array $domainIdMap)
    {
        foreach ($allDomains as &$domainInfo) {
            $domain = isset($domainInfo['domain']) ? strtolower((string)$domainInfo['domain']) : '';
            if ($domain !== '' && isset($domainIdMap[$domain])) {
                $domainInfo['id'] = $domainIdMap[$domain];
            }

            if (!isset($domainInfo['id'])) {
                $domainInfo['id'] = '';
            }
        }
        unset($domainInfo);

        return $allDomains;
    }

    /**
     * 是否存在已进入续期窗口的域名
     *
     * @param array $allDomains
     *
     * @return bool
     */
    protected function hasRenewableDomains(array $allDomains)
    {
        foreach ($allDomains as $domainInfo) {
            $days = isset($domainInfo['days']) ? (int)$domainInfo['days'] : 0;
            if ($days <= self::RENEW_BEFORE_DAYS) {
                return true;
            }
        }

        return false;
    }

    /**
     * 获取匹配 token
     *
     * 据观察，每次登录后此 token 不会改变，故可以只获取一次，多次使用
     *
     * @param string $domainStatusPage
     *
     * @return string
     * @throws LlfException
     */
    protected function getToken(string $domainStatusPage)
    {
        if (preg_match(self::TOKEN_REGEX, $domainStatusPage, $matches)) {
            return $matches['token'];
        }

        if (preg_match('/<input\b[^>]*\bvalue=["\'](?P<token>[^"\']+)["\'][^>]*\bname=["\']token["\']/i', $domainStatusPage, $matches)) {
            return $matches['token'];
        }

        throw new LlfException(34520004);
    }

    /**
     * 获取域名状态页面
     *
     * @return string
     * @throws LlfException
     */
    protected function getDomainStatusPage()
    {
        try {
            $resp = autoRetry(function (&$jar) {
                return $this->client->get(self::DOMAIN_STATUS_URL, [
                    'headers' => [
                        'Referer' => 'https://my.freenom.com/clientarea.php'
                    ],
                    'cookies' => $jar
                ]);
            }, $this->maxRequestRetryCount, [&$this->jar], !$this->cookieSessionMode);

            $page = (string)$resp->getBody();
        } catch (\Exception $e) {
            throw new LlfException(34520013, $e->getMessage());
        }

        if (!preg_match(self::LOGIN_STATUS_REGEX, $page)) {
            throw new LlfException(34520009);
        }

        return $page;
    }

    /**
     * 获取 My Domains 页面
     *
     * @return string
     * @throws LlfException
     */
    protected function getDomainListPage()
    {
        try {
            $resp = autoRetry(function (&$jar) {
                return $this->client->get(self::DOMAIN_LIST_URL, [
                    'headers' => [
                        'Referer' => 'https://my.freenom.com/clientarea.php'
                    ],
                    'cookies' => $jar
                ]);
            }, $this->maxRequestRetryCount, [&$this->jar], !$this->cookieSessionMode);

            $page = (string)$resp->getBody();
        } catch (\Exception $e) {
            throw new LlfException(34520013, $e->getMessage());
        }

        if (!preg_match(self::LOGIN_STATUS_REGEX, $page)) {
            throw new LlfException(34520009);
        }

        return $page;
    }

    /**
     * 续期所有域名
     *
     * @param array $allDomains
     * @param string $token
     *
     * @return bool
     */
    public function renewAllDomains(array $allDomains, string $token)
    {
        $renewalSuccessArr = [];
        $renewalFailuresArr = [];
        $domainStatusArr = [];

        $renewalDomains = [];

        foreach ($allDomains as $d) {
            $domain = $d['domain'];
            $days = isset($d['days']) ? (int)$d['days'] : 0;
            $id = isset($d['id']) ? (int)$d['id'] : 0;

            // 免费域名只允许在到期前 14 天内续期
            if ($days <= self::RENEW_BEFORE_DAYS) {
                if ($id <= 0) {
                    $renewalFailuresArr[] = $domain;
                    $domainStatusArr[$domain] = $days;

                    continue;
                }

                $renewalDomains[] = [
                    'domain' => $domain,
                    'days' => $days,
                    'id' => $id,
                ];

                continue;
            }

            // 记录无需续期域名的剩余天数
            $domainStatusArr[$domain] = $days;
        }

        foreach ($this->renewDomainsConcurrently($renewalDomains, $token) as $renewalResult) {
            $domain = $renewalResult['domain'];
            $days = $renewalResult['days'];

            if ($renewalResult['success']) {
                $renewalSuccessArr[] = $domain;

                continue; // 续期成功的域名无需记录过期天数
            }

            $renewalFailuresArr[] = $domain;
            $domainStatusArr[$domain] = $days;
        }

        // 存在续期操作
        if ($renewalSuccessArr || $renewalFailuresArr) {
            $data = [
                'username' => $this->username,
                'renewalSuccessArr' => $renewalSuccessArr,
                'renewalFailuresArr' => $renewalFailuresArr,
                'domainStatusArr' => $domainStatusArr,
            ];
            $result = Message::send('', lang('100039'), 2, $data);

            system_log(sprintf(
                lang('100040'),
                count($renewalSuccessArr),
                count($renewalFailuresArr),
                $result ? lang('100041') : ''
            ));

            Log::info(sprintf(lang('100042'), $this->username), $data);

            return true;
        }

        // 不存在续期操作
        if (config('notice_freq') === 1) {
            $data = [
                'username' => $this->username,
                'domainStatusArr' => $domainStatusArr,
            ];
            Message::send('', lang('100043'), 3, $data);
        } else {
            system_log(lang('100044'));
        }

        system_log(sprintf(lang('100045'), $this->username));

        return true;
    }

    /**
     * 并发续期域名
     *
     * @param array $renewalDomains
     * @param string $token
     *
     * @return array
     */
    protected function renewDomainsConcurrently(array $renewalDomains, string $token)
    {
        if (empty($renewalDomains)) {
            return [];
        }

        $results = [];
        $requests = function () use ($renewalDomains, $token) {
            foreach ($renewalDomains as $index => $domainInfo) {
                yield $index => function () use ($domainInfo, $token) {
                    return $this->renewAsync((int)$domainInfo['id'], $token)
                        ->then(function ($success) use ($domainInfo) {
                            return [
                                'domain' => $domainInfo['domain'],
                                'days' => (int)$domainInfo['days'],
                                'id' => (int)$domainInfo['id'],
                                'success' => (bool)$success,
                            ];
                        });
                };
            }
        };

        $pool = new Pool($this->client, $requests(), [
            'concurrency' => self::RENEW_CONCURRENCY,
            'fulfilled' => function ($result, $index) use (&$results) {
                $results[$index] = $result;
            },
            'rejected' => function ($reason, $index) use (&$results, $renewalDomains) {
                $domainInfo = $renewalDomains[$index];
                $id = (int)$domainInfo['id'];
                $message = $reason instanceof \Throwable ? $reason->getMessage() : (string)$reason;
                $errorMsg = sprintf(lang('100046'), $message, $id, $this->username);
                system_log($errorMsg);
                Message::send($errorMsg);

                $results[$index] = [
                    'domain' => $domainInfo['domain'],
                    'days' => (int)$domainInfo['days'],
                    'id' => $id,
                    'success' => false,
                ];
            },
        ]);

        $pool->promise()->wait();
        ksort($results);

        return array_values($results);
    }

    /**
     * 获取异步请求失败状态码
     *
     * @param mixed $reason
     *
     * @return int
     */
    protected function getFailureStatusCode($reason)
    {
        if ($reason instanceof RequestException && $reason->hasResponse()) {
            return $reason->getResponse()->getStatusCode();
        }

        if ($reason instanceof \Throwable && preg_match('/\b(?P<code>[1-5]\d{2})\b/', $reason->getMessage(), $matches)) {
            return (int)$matches['code'];
        }

        return 0;
    }

    /**
     * 并发续期遇到 405 时刷新 AWS WAF token
     *
     * cookies 模式不处理 aws-waf-token。
     *
     * @param mixed $reason
     *
     * @return bool
     */
    protected function refreshAwsWafTokenForRenewal($reason)
    {
        if ($this->cookieSessionMode || $this->getFailureStatusCode($reason) !== 405) {
            return false;
        }

        system_log('检测到 405 人机验证，重新获取 aws waf token');
        delGlobalValue(CommonConst::AWS_WAF_TOKEN);
        $this->jar->setCookie(buildAwsWafCookie(getAwsWafToken()));

        return true;
    }

    /**
     * 异步续期单个域名，失败时按最大重试次数重试
     *
     * @param int $id
     * @param string $token
     * @param int $retryCount
     *
     * @return \GuzzleHttp\Promise\PromiseInterface
     */
    protected function renewAsync(int $id, string $token, int $retryCount = 0)
    {
        return $this->client->postAsync(self::RENEW_DOMAIN_URL, $this->getRenewRequestOptions($id, $token))
            ->then(function ($resp) {
                $body = (string)$resp->getBody();

                return stripos($body, 'Order Confirmation') !== false;
            }, function ($reason) use ($id, $token, $retryCount) {
                if ($retryCount < $this->maxRequestRetryCount) {
                    try {
                        $this->refreshAwsWafTokenForRenewal($reason);
                    } catch (\Throwable $e) {
                        $message = $e->getMessage();
                        $errorMsg = sprintf(lang('100046'), $message, $id, $this->username);
                        system_log($errorMsg);
                        Message::send($errorMsg);

                        return false;
                    }

                    return $this->renewAsync($id, $token, $retryCount + 1);
                }

                $message = $reason instanceof \Throwable ? $reason->getMessage() : (string)$reason;
                $errorMsg = sprintf(lang('100046'), $message, $id, $this->username);
                system_log($errorMsg);
                Message::send($errorMsg);

                return false;
            });
    }

    /**
     * 构造续期请求参数
     *
     * @param int $id
     * @param string $token
     *
     * @return array
     */
    protected function getRenewRequestOptions(int $id, string $token)
    {
        return [
            'headers' => [
                'Referer' => sprintf('https://my.freenom.com/domains.php?a=renewdomain&domain=%s', $id),
                'Content-Type' => 'application/x-www-form-urlencoded'
            ],
            'form_params' => [
                'token' => $token,
                'renewalid' => $id,
                sprintf('renewalperiod[%s]', $id) => '12M', // 续期一年
                'paymentmethod' => 'credit', // 支付方式：信用卡
            ],
            'cookies' => $this->jar
        ];
    }

    /**
     * 续期单个域名
     *
     * @param int $id
     * @param string $token
     *
     * @return bool
     */
    protected function renew(int $id, string $token)
    {
        try {
            $resp = autoRetry(function ($token, $id, &$jar) {
                $options = $this->getRenewRequestOptions($id, $token);
                $options['cookies'] = $jar;

                return $this->client->post(self::RENEW_DOMAIN_URL, $options);
            }, $this->maxRequestRetryCount, [$token, $id, &$this->jar], !$this->cookieSessionMode);

            $resp = (string)$resp->getBody();

            return stripos($resp, 'Order Confirmation') !== false;
        } catch (\Exception $e) {
            $errorMsg = sprintf(lang('100046'), $e->getMessage(), $id, $this->username);
            system_log($errorMsg);
            Message::send($errorMsg);

            return false;
        }
    }

    /**
     * 二维数组去重
     *
     * @param array $array 原始数组
     * @param array $keys 可指定对应的键联合
     *
     * @return bool
     */
    public function arrayUnique(array &$array, array $keys = [])
    {
        if (!isset($array[0]) || !is_array($array[0])) {
            return false;
        }

        if (empty($keys)) {
            $keys = array_keys($array[0]);
        }

        $tmp = [];
        foreach ($array as $k => $items) {
            $combinedValues = [];
            foreach ($keys as $key) {
                $combinedValues[$key] = $items[$key] ?? null;
            }

            $combinedKey = json_encode($combinedValues, JSON_UNESCAPED_UNICODE);

            if (isset($tmp[$combinedKey])) {
                unset($array[$k]);
            } else {
                $tmp[$combinedKey] = $k;
            }
        }
        unset($tmp);

        return true;
    }

    /**
     * 获取 FreeNom 账户信息
     *
     * @return array
     * @throws LlfException
     */
    protected function getAccounts()
    {
        $accounts = [];
        $multipleAccounts = preg_replace('/\s/', '', env('MULTIPLE_ACCOUNTS'));
        if (preg_match_all('/<(?P<u>.*?)>@<(?P<p>.*?)>/i', $multipleAccounts, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $accounts[] = [
                    'username' => $m['u'],
                    'password' => $m['p']
                ];
            }
        }

        $username = env('FREENOM_USERNAME');
        $password = env('FREENOM_PASSWORD');
        if ($username && $password) {
            $accounts[] = [
                'username' => $username,
                'password' => $password
            ];
        }

        if (empty($accounts)) {
            throw new LlfException(34520001);
        }

        // 去重
        $this->arrayUnique($accounts);

        return $accounts;
    }

    /**
     * 发送异常报告
     *
     * @param \Throwable $e
     */
    private function sendExceptionReport($e)
    {
        Message::send(sprintf(
            lang('100047'),
            $e->getFile(),
            $e->getLine(),
            $e->getMessage(),
            $this->username
        ), lang('100048') . $e->getMessage());
    }

    /**
     * @throws LlfException
     * @throws \Exception
     */
    public function handle()
    {
        $cookieFilePath = $this->getCookieFilePath();
        if ($cookieFilePath !== '') {
            $accounts = [
                [
                    'username' => env('FREENOM_USERNAME') ?: sprintf('cookies:%s', basename($cookieFilePath)),
                    'password' => '',
                    'cookie_file' => $cookieFilePath,
                ],
            ];
        } else {
            $accounts = $this->getAccounts();
        }
        $totalAccounts = count($accounts);

        system_log(sprintf(lang('100049'), $totalAccounts));

        foreach ($accounts as $index => $account) {
            try {
                $this->username = $account['username'];
                $this->password = $account['password'];

                $num = $index + 1;
                system_log(sprintf(lang('100050'), get_local_num($num), $this->username, $num, $totalAccounts));

                $usingCookieFile = isset($account['cookie_file']) && $account['cookie_file'] !== '';
                $this->cookieSessionMode = $usingCookieFile;
                if (isset($account['cookie_file']) && $account['cookie_file'] !== '') {
                    $this->jar = $this->buildCookieJarFromFile($account['cookie_file']); // 所有请求共用一个 CookieJar 实例
                    system_log(sprintf(lang('100144'), $account['cookie_file']));
                } else {
                    $this->jar = new CookieJar(); // 所有请求共用一个 CookieJar 实例
                }

                if (!$usingCookieFile) {
                    $this->prepareAwsWafToken();
                    $this->login($this->username, $this->password);
                }

                $domainStatusPage = $this->getDomainStatusPage();
                $allDomains = $this->getAllDomains($domainStatusPage);
                $token = $this->getToken($domainStatusPage);

                if ($this->hasRenewableDomains($allDomains)) {
                    try {
                        $domainIdMap = $this->getDomainIdMap($this->getDomainListPage());
                        $allDomains = $this->fillDomainIds($allDomains, $domainIdMap);
                    } catch (LlfException $e) {
                        system_log(sprintf(lang('100129'), $e->getMessage()));
                    }
                }

                $this->renewAllDomains($allDomains, $token);
            } catch (WarningException $e) {
                system_log(sprintf(lang('100129'), $e->getMessage()));
            } catch (LlfException $e) {
                system_log(sprintf(lang('100051'), $e->getMessage()));
                $this->sendExceptionReport($e);
            } catch (\Throwable $e) {
                system_log(sprintf(lang('100052'), $e->getMessage()), $e->getTrace());
                $this->sendExceptionReport($e);
            }
        }
    }
}
