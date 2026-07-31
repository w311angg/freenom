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

use Luolongfei\App\Exceptions\LlfException;
use Luolongfei\App\Exceptions\WarningException;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use Luolongfei\Libs\Log;
use Luolongfei\Libs\Message;
use GuzzleHttp\Cookie\SetCookie;

class FreeNom extends Base
{
    const VERSION = 'v0.6.2';

    const TIMEOUT = 60;

    // FreeNom登录地址
    const LOGIN_URL = 'https://my.freenom.com/dologin.php';

    // 域名状态地址
    const DOMAIN_STATUS_URL = 'https://my.freenom.com/domains.php?a=renewals';

    // 域名续期地址
    const RENEW_DOMAIN_URL = 'https://my.freenom.com/domains.php?submitrenewals=true';

    // 匹配token的正则
    const TOKEN_REGEX = '/name="token"\svalue="(?P<token>[^"]+)"/i';

    // 匹配域名信息的正则
    // 只匹配域名和 renewdomain 的 domain id，不再依赖 “Days Until Expiry” 到期天数
    const DOMAIN_INFO_REGEX = '/<tr\b[^>]*>\s*<td\b[^>]*>\s*(?P<domain>[^<]+?)\s*<\/td>(?:(?!<\/tr>).)*?(?:domains\.php\?a=renewdomain(?:&amp;|&)domain=|[?&](?:amp;)?domain=)(?P<id>\d+)(?:(?!<\/tr>).)*?<\/tr>/is';

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
     * 匹配获取所有域名
     *
     * @param string $domainStatusPage
     *
     * @return array
     * @throws LlfException
     * @throws WarningException
     */
    protected function getAllDomains(string $domainStatusPage)
    {
        if (preg_match(self::NO_DOMAIN_REGEX, $domainStatusPage, $m)) {
            throw new WarningException(34520014, [$this->username, $m['msg']]);
        }

        if (!preg_match_all(self::DOMAIN_INFO_REGEX, $domainStatusPage, $allDomains, PREG_SET_ORDER)) {
            throw new LlfException(34520003);
        }

        foreach ($allDomains as &$domainInfo) {
            $domainInfo['domain'] = html_entity_decode(trim($domainInfo['domain']), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $domainInfo['id'] = trim($domainInfo['id']);
            $domainInfo['days'] = '0'; // 新页面可能不再返回到期天数，续期逻辑不再依赖此字段
        }
        unset($domainInfo);

        return $allDomains;
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
        if (!preg_match(self::TOKEN_REGEX, $domainStatusPage, $matches)) {
            throw new LlfException(34520004);
        }

        return $matches['token'];
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

        foreach ($allDomains as $d) {
            $domain = $d['domain'];
            $days = isset($d['days']) ? (int)$d['days'] : 0;
            $id = $d['id'];

            // 忽略到期天数，匹配到续期页中的域名后直接尝试续期
            $renewalResult = $this->renew($id, $token);

            sleep(1);

            if ($renewalResult) {
                $renewalSuccessArr[] = $domain;

                continue; // 续期成功的域名无需记录过期天数
            } else {
                $renewalFailuresArr[] = $domain;
            }

            // 记录续期失败域名，兼容通知模板中仍然需要 domainStatusArr 的情况
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
                return $this->client->post(self::RENEW_DOMAIN_URL, [
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
                    'cookies' => $jar
                ]);
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
