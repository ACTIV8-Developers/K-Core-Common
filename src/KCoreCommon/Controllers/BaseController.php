<?php

namespace KCoreCommon\Controllers;

abstract class BaseController extends Controller
{
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
     * Set response type to JSON.
     *
     * @param array $data
     * @param int $code
     * @param int $options
     * @return ResponseInterface|Response
     */
    public function json($data, $code = 200, $options = 0): ResponseInterface
    {
        $response = new Response();
        $response->headers->set('Content-Type', 'application/json');
        $response->setStatusCode($code);
        $response->setBody(json_encode($data, $options | JSON_INVALID_UTF8_IGNORE | JSON_INVALID_UTF8_SUBSTITUTE));

        return $response;
    }

    /**
     * @param $url
     * @return Response
     */
    public function redirect($url): ResponseInterface
    {
        $response = new Response();
        $response
            ->setStatusCode(301)
            ->setHeader('Location', PURL::base($url));
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


    protected function getAuthorizationHeader()
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

    protected function sanitizeDate($value)
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
    public function convertDate($date)
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
    public function currentDateTime()
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
    public function toFrontDateTime($date, $format = "m/d/Y H:i:s")
    {
        if ($date) {
            $dt = Carbon::parse($date, "UTC");
            $dt->setTimezone($this->tz);
            return $dt->format($format);
        }
        return null;
    }

    public function toFrontDate($date, $format = "m/d/Y")
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
    protected function buffer($view, array $data = [])
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
    protected function getDaoForObject($class)
    {
        $dao = (new BaseDAO(new $class));
        $dao->setContainer($this->container);
        return $dao;
    }

    /**
     * @return string
     */
    protected function getApiResourceUrl()
    {
        return str_replace(API_PREFIX . '/', "", $this->request->getUri());
    }

    protected function beautifyFilename($filename)
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
        $filename = trim($filename, '.-');
        return $filename;
    }

    protected function getFileExtension($filename)
    {
        $x = explode('.', $filename);
        return '.' . end($x);
    }

    protected function createRandomHash($value)
    {
        return substr(hash('sha512', $value . rand(1, 100)), 0, 24);
    }

    /* Project specific
    ============================================================ */
    protected function phoneNumber($areaCode, $phoneNumber, $phoneExtension)
    {
        return ($areaCode ? '(' . $areaCode . ') ' : '') . $phoneNumber . ' ' . $phoneExtension;
    }

    public function getStartEndDates($filterDays)
    {
        $endDate = $this->currentDateTime();
        switch ($filterDays) {
            case 'This week':
                $startDate = date('Y-m-d H:i:s', strtotime('last monday'));
                break;
            case 'Previous week':
                $startDate = date('Y-m-d H:i:s', strtotime('last monday'));
                $endDate = date('Y-m-d H:i:s', strtotime('last sunday'));
                break;
            case 'Last 7 days':
                $startDate = date('Y-m-d H:i:s', strtotime('-7 days'));
                break;
            case 'This month':
                $startDate = date('Y-m-d H:i:s', strtotime('first day of this month'));
                break;
            case 'Last 30 days':
                $startDate = date('Y-m-d H:i:s', strtotime('-30 days'));
                break;
            case 'Last month':
                $startDate = date('Y-m-d H:i:s', strtotime('first day of last month'));
                $endDate = date('Y-m-d H:i:s', strtotime('last day of last month'));
                break;
            case 'This quarter':
                $current_quarter = ceil(date('n') / 3);
                $startDate = date('Y-m-d H:i:s', strtotime(date('Y') . '-' . (($current_quarter * 3) - 2) . '-1'));
                $endDate = date('Y-m-d H:i:s', strtotime(date('Y') . '-' . (($current_quarter * 3)) . '-1'));
                break;
            case 'Last quarter':
                [$startDate, $endDate] = $this->getLastQuarter();
                break;
            case 'Last 6 months':
                $startDate = date('Y-m-d H:i:s', strtotime('-6 months'));
                break;
            case 'Last 12 months':
                $startDate = date('Y-m-d H:i:s', strtotime('-12 months'));
                break;
            default:
                $startDate = date('Y-m-d H:i:s', strtotime('last monday'));
                break;
        }
        return [$startDate, $endDate];
    }

    public function getLastQuarter()
    {
        $current_month = date('m');
        $current_year = date('Y');

        if ($current_month >= 1 && $current_month <= 3) {
            $start_date = strtotime('1-October-' . ($current_year - 1));  // timestamp or 1-October Last Year 12:00:00 AM
            $end_date = strtotime('1-January-' . $current_year);  // // timestamp or 1-January  12:00:00 AM means end of 31 December Last year
        } else if ($current_month >= 4 && $current_month <= 6) {
            $start_date = strtotime('1-January-' . $current_year);  // timestamp or 1-Januray 12:00:00 AM
            $end_date = strtotime('1-April-' . $current_year);  // timestamp or 1-April 12:00:00 AM means end of 31 March
        } else if ($current_month >= 7 && $current_month <= 9) {
            $start_date = strtotime('1-April-' . $current_year);  // timestamp or 1-April 12:00:00 AM
            $end_date = strtotime('1-July-' . $current_year);  // timestamp or 1-July 12:00:00 AM means end of 30 June
        } else if ($current_month >= 10 && $current_month <= 12) {
            $start_date = strtotime('1-July-' . $current_year);  // timestamp or 1-July 12:00:00 AM
            $end_date = strtotime('1-October-' . $current_year);  // timestamp or 1-October 12:00:00 AM means end of 30 September
        }
        return [date('Y-m-d H:i:s', $start_date), date('Y-m-d H:i:s', $end_date)];
    }

    public function appendQueryForFields($queryParam, $fields, $query): string
    {
        if (!empty($query)) {
            $queryParam .= empty($queryParam) ? " (" : " AND (";
            $chunks = explode(' ', $query);
            foreach ($chunks as $chunk) {
                $likeQuery = "";
                foreach ($fields as $f) {
                    $likeQuery .= sprintf(" %s LIKE '%%%s%%' OR ", $f, $chunk);
                }
                $likeQuery = substr($likeQuery, 0, strlen($likeQuery) - 3);
                $queryParam .= sprintf("(%s) AND ", $likeQuery);
            }
            return substr($queryParam, 0, strlen($queryParam) - 4) . ")";
        }
        return $queryParam;
    }

    protected function elasticSearch(string $index, string $query, array $queryParams, int $limit = 50): array
    {
//        $data = [
//            'query' => [
//                'bool' => [
//                    'must' => [
//                        'query_string' => [
//                            'fields' => $queryParams,
//                            'query' => '*' . $query . '*'
//                        ]
//                    ],
//                    'filter' => [
//                        'term' => [
//                            'CompanyID' => $this->user['Contact']['CompanyID']
//                        ]
//                    ]
//                ]
//            ],
//            "size" => $limit
//        ];
//        $json = json_encode($data);
//
//        $purli = (new Purli())
//            ->setHeader('Content-Type', 'application/json')
//            ->setParams($json)
//            ->post(sprintf("http://elasticsearch:9200/%s/_search", $index))
//            ->close();
//
//        $response = $purli->response();
//        $array = $response->asArray();
        $result = $this->elastic->search([
            'index' => $index,
            'body' => [
                'query' => [
                    'bool' => [
                        'must' => [
                            'query_string' => [
                                'fields' => $queryParams,
                                'query' => '*' . $query . '*',
                            ]
                        ],
                        'filter' => [
                            'term' => [
                                'CompanyID' => $this->user['Contact']['CompanyID']
                            ]
                        ]
                    ]
                ]
            ],
            'size' => $limit
        ]);

        return $result['hits']['hits'];
    }
}
?>