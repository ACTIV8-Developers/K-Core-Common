<?php

namespace Common\Controllers;

use Common\DAOTrait;
use Common\Models\BaseDAO;
use Carbon\Carbon;
use Core\Container\Container;
use Core\Core\Controller;
use Core\Http\Interfaces\ResponseInterface;
use Core\Http\Response;
use DateTime;
use PURL;

/**
 * Class RootController
 */
abstract class RootController extends Controller
{
    use DAOTrait;

    /**
     * BaseController constructor.
     *
     * @param Container $container
     */
    public function __construct(Container $container)
    {
        $this->container = $container;
    }

    /* Responses
    ============================================================ */
    /**
     * Set response type to binary file.
     *
     * @param $path
     * @param string $name
     * @return Response
     */
    public function file($path, string $name = "file"): Response
    {
        $response = new Response();
        $response->setHeader('Content-Description', 'File Transfer');
        $response->setHeader('Content-Type', 'application/octet-stream');
        $response->setHeader('Access-Control-Expose-Headers', 'Origin, Authorization, Content-Type, Accept-Ranges');
        $response->setHeader('Content-Disposition', 'attachment; filename="' . $name . '"');
        $response->setHeader('Expires', '0');
        $response->setHeader('Cache-Control', 'must-revalidate');
        $response->setHeader('Pragma', 'public');
        $response->setHeader('Content-Length', filesize($path));
        $response->setBody(file_get_contents($path));

        return $response;
    }

    /**
     * Set response type to JSON.
     *
     * @param array $data
     * @param int $code
     * @param int $options
     * @return ResponseInterface|Response
     */
    public function json(array $data, int $code = 200, int $options = 0)
    {
        $response = new Response();
        $response->headers->set('Content-Type', 'application/json');
        $response->setStatusCode($code);
        $response->setBody(json_encode($data, $options | JSON_INVALID_UTF8_IGNORE | JSON_INVALID_UTF8_SUBSTITUTE));

        return $response;
    }

    public function jsonData($data, $code = 200)
    {
        $result = [
            'status' => 0,
            'message' => "OK",
            'data' => $data
        ];

        return $this->json($result, $code);
    }

    public function jsonCreate($opResult, $code = 201, $codeError = 400, $msgOK = 'OK', $msgError = 'ERROR')
    {
        return $this->jsonResponse($opResult, $code, $codeError, $msgOK, $msgError);
    }

    public function jsonUpdate($opResult, $code = 200, $codeError = 400, $msgOK = 'OK', $msgError = 'ERROR')
    {
        return $this->jsonResponse($opResult, $code, $codeError, $msgOK, $msgError);
    }

    public function jsonDelete($opResult, $code = 200, $codeError = 400, $msgOK = 'OK', $msgError = 'ERROR')
    {
        return $this->jsonResponse($opResult, $code, $codeError, $msgOK, $msgError);
    }

    public function jsonResponse($opResult, $code, $codeError, $msgOK, $msgError)
    {
        $code = $opResult ? $code : $codeError;
        $result = [
            'status' => $opResult ? 0 : 1,
            'message' => $opResult ? $msgOK : $msgError,
            'data' => [
                'id' => $opResult
            ]
        ];

        return $this->json($result, $code);
    }

    /**
     * @param $url
     * @return Response
     */
    public function redirect($url): Response
    {
        $response = new Response();
        $response
            ->setStatusCode(301)
            ->setHeader('Location', \PURL::base($url));
        return $response;
    }

    /* Input reading
    ============================================================ */
    /**
     * @param $key
     * @param null $filter
     * @return mixed
     */
    public function get($key = null, $filter = null)
    {
        if ($key !== null) {
            if (!$this->request->get->has($key)) {
                return null;
            }
            if ($filter !== null) {
                return $this->filterVar($this->request->get->get($key), $filter);
            } else {
                return $this->request->get->get($key);
            }
        }
        return $this->request->get->all();
    }

    /**
     * @param null $key
     * @param null $filter
     * @return mixed
     */
    public function post($key = null, $filter = null)
    {
        if ($key !== null) {
            if (!$this->request->post->has($key)) {
                return null;
            }
            if ($filter !== null) {
                return $this->filterVar($this->request->post->get($key), $filter);
            } else {
                return $this->request->post->get($key);
            }
        }
        return $this->request->post->all();
    }

    /**
     * @param $key
     * @param null $filter
     * @param null $option
     * @return mixed
     */
    public function data($key = null, $filter = null, $option = null)
    {
        if ($key !== null) {
            if (isset($this->container['data'][$key])) {
                if ($filter !== null) {
                    return $this->filterVar($this->container['data'][$key], $filter, $option);
                } else {
                    return $this->container['data'][$key];
                }
            } else {
                return null;
            }
        }
        return $this->container['data'];
    }

    /**
     * @param $value
     * @param $filter
     * @param null $option
     * @return mixed
     */
    protected function filterVar($value, $filter, $option = null)
    {
        if ($filter == FILTER_SANITIZE_DATE) {
            return $this->sanitizeDate($value);
        } else if ($filter == FILTER_SANITIZE_NUMBER_FLOAT && !is_numeric($value)) {
            return null;
        } else if ($filter == FILTER_SANITIZE_NUMBER_INT && !is_numeric($value)) {
            return null;
        } else if ($filter == FILTER_VALIDATE_EMAIL) {
            $value = trim($value);
        }

        return filter_var($value, $filter, $option);
    }

    protected function getAuthorizationHeader(): ?string
    {
        $headers = null;
        if (isset($_SERVER['Authorization'])) {
            $headers = trim($_SERVER["Authorization"]);
        } else if (isset($_SERVER['HTTP_AUTHORIZATION'])) { // Nginx or fast CGI
            $headers = trim($_SERVER["HTTP_AUTHORIZATION"]);
        } elseif (function_exists('apache_request_headers')) {
            $requestHeaders = apache_request_headers();
            // Server-side fix for bug in old Android versions (a nice side-effect of this fix means we don't care about capitalization for Authorization)
            $requestHeaders = array_combine(array_map('ucwords', array_keys($requestHeaders)), array_values($requestHeaders));

            if (isset($requestHeaders['Authorization'])) {
                $headers = trim($requestHeaders['Authorization']);
            }
        }
        return $headers;
    }

    protected function getBearerToken()
    {
        $headers = $this->getAuthorizationHeader();
        // HEADER: Get the access token from the header
        if (!empty($headers)) {
            if (preg_match('/Bearer\s(\S+)/', $headers, $matches)) {
                return $matches[1];
            }
        }
        return null;
    }

    protected function sanitizeDate($value): ?string
    {
        if (empty($value)) {
            return null;
        }
        $format = 'Y-m-d H:i:s';
        $d = DateTime::createFromFormat($format, $value);
        // The Y ( 4 digits year ) returns TRUE for any integer with any number of digits so changing the comparison from == to === fixes the issue.
        return ($d && $d->format($format) === $value) ? $value : null;
    }

    /* Dates
     ============================================================ */
    /**
     * Converts to SQL standard date
     *
     * @param string
     * @param string
     * @return string|null
     */
    public function convertDate($date): ?string
    {
        if ($date) {
            return date('Y-m-d', strtotime($date));
        }
        return null;
    }

    /**
     * Converts to SQL standard date
     *
     * @param string
     * @param string
     * @return string|null
     */
    public function currentDateTime(): ?string
    {
        return date('Y-m-d H:i:s');
    }

    /**
     * Converts to SQL standard date
     *
     * @param string
     * @param string
     * @return string|null
     */
    public function toFrontDateTime($date, $format = "m/d/Y H:i"): ?string
    {
        if ($date) {
            $dt = Carbon::parse($date);
            return $dt->format($format);
        }
        return null;
    }

    public function toFrontDate($date, $format = "m/d/Y"): ?string
    {
        if ($date) {
            $dt = Carbon::parse($date);
            return $dt->format($format);
        }
        return null;
    }

    /* Helpers
    ============================================================ */
    /**
     * Buffer output and return it as string.
     *
     * @param string $view
     * @param array $data
     * @return string
     */
    protected function buffer($view, array $data = []): string
    {
        // Extract variables.
        extract($data);

        // Start buffering.
        ob_start();

        // Load view file (root location is declared in viewsPath var).
        include $this->container->get('config')['viewsPath'] . '/' . $view . '.php';

        // Return string.
        $buffer = ob_get_contents();
        ob_end_clean();
        return $buffer;
    }

    /**
     * @param String
     * @return BaseDAO
     */
    protected function getDaoForObject($class): BaseDAO
    {
        $dao = (new BaseDAO(new $class));
        $dao->setContainer($this->container);
        return $dao;
    }

    /**
     * @return string
     */
    protected function getApiResourceUrl(): string
    {
        return str_replace(API_PREFIX . '/', "", $this->request->getUri());
    }

    protected function beautifyFilename($filename): string
    {
        // reduce consecutive characters
        $filename = preg_replace(array(
            // "file   name.zip" becomes "file-name.zip"
            '/ +/',
            // "file___name.zip" becomes "file-name.zip"
            '/_+/',
            // "file---name.zip" becomes "file-name.zip"
            '/-+/'
        ), '_', $filename);
        $filename = preg_replace(array(
            // "file--.--.-.--name.zip" becomes "file.name.zip"
            '/-*\.-*/',
            // "file...name..zip" becomes "file.name.zip"
            '/\.{2,}/'
        ), '.', $filename);
        // lowercase for windows/unix interoperability http://support.microsoft.com/kb/100625
        $filename = mb_strtolower($filename, mb_detect_encoding($filename));
        // ".file-name.-" becomes "file-name"

        return trim($filename, '.-');
    }

    protected function getFileExtension($filename): string
    {
        $x = explode('.', $filename);
        return '.' . end($x);
    }

    protected function createRandomHash($value)
    {
        return substr(hash('sha512', $value . rand(1, 100)), 0, 24);
    }

    protected function phoneNumber($areaCode, $phoneNumber, $phoneExtension): string
    {
        return ($areaCode ? '(' . $areaCode . ') ' : '') . $phoneNumber . ' ' . $phoneExtension;
    }

    protected function slugify($string, $separator = '-')
    {
        $accents_regex = '~&([a-z]{1,2})(?:acute|cedil|circ|grave|lig|orn|ring|slash|th|tilde|uml);~i';
        $special_cases = array('&' => 'and', "'" => '');
        $string = mb_strtolower(trim($string), 'UTF-8');
        $string = str_replace(array_keys($special_cases), array_values($special_cases), $string);
        $string = preg_replace($accents_regex, '$1', htmlentities($string, ENT_QUOTES, 'UTF-8'));
        $string = preg_replace("/[^a-z0-9]/u", "$separator", $string);
        return preg_replace("/[$separator]+/u", "$separator", $string);
    }

    protected function urlExists($url): bool
    {
        $urlheaders = get_headers($url);
        $urlmatches  = preg_grep('/200 ok/i', $urlheaders);
        return !empty($urlmatches);
    }
}
