<?php

namespace Keyojel\Chart;

use Exception;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\ServiceProvider;
use Keyojel\Chart\Util\Constants;
use Keyojel\Chart\Util\Misc;

use Keyojel\Chart\Helpers\Arr as arr;
use Keyojel\Chart\Helpers\Dev as dev;
use Keyojel\Chart\Helpers\Str as str;
use Keyojel\Chart\Helpers\Yml as yml;


class LaraChartServiceProvider extends ServiceProvider
{
    use Constants;
    use Misc;

    public $max_requests;
    public $options;

    public $outstanding_requests;
    public $multi_handle;

    /**
     * Bootstrap application services.
     *
     * @return void
     */
    public function boot()
    {
        $this->registerServices();
    }

    /**
     * Sets a value in an array using the dot notation.
     *
     * @param string $key
     * @param mixed  $value
     * @param array  $array
     *
     * @return bool
     */
    function array_set($key, $value, &$array)
    {
        return arr::set($key, $value, $array);
    }

    /**
     * Returns the first element of an array.
     *
     * @param array $array
     *
     * @return mixed $value
     */
    function array_first($array)
    {
        return arr::first($array);
    }

    /**
     * Returns the last element of an array.
     *
     * @param array $array
     *
     * @return mixed $value
     */
    function array_last($array)
    {
        return arr::last($array);
    }

    /**
     * Converts a string or an object to an array.
     *
     * @param string|object $var
     *
     * @return array|null
     */
    function to_array($var)
    {
        return arr::dump($var);
    }

    /**
     * Converts an array to an object.
     *
     * @param array $array
     *
     * @return object|null
     */
    function to_object($array)
    {
        return arr::toObject($array);
    }

    /**
     * Detects if the given value is an associative array.
     *
     * @param array $array
     *
     * @return bool
     */
    function is_assoc($array)
    {
        return arr::isAssoc($array);
    }

    /**
     * Loads the content of a yaml file into an array.
     *
     * @param $string
     * @return bool
     */
    function is_yml($string)
    {
        return yml::isValid($string);
    }

    /**
     * Validates if a given file contains yaml syntax.
     *
     * @codeCoverageIgnore
     *
     * @param $string
     * @return bool
     */
    function is_yml_file($string)
    {
        return yml::isValidFile($string);
    }

    /**
     * Sets a value in an ymlfile using the dot notation.
     *
     * @param string $key
     * @param mixed  $value
     * @param string $ymlfile
     * @return bool
     */
    function yml_set_file($key, $value, $ymlfile)
    {
        return yml::setFile($key, $value, $ymlfile);
    }

    /**
     * Sets a value in an yml string using the dot notation.
     *
     * @param string $key
     * @param mixed  $value
     * @param string $yml
     *
     * @return bool
     */
    function yml_set($key, $value, &$yml)
    {
        return yml::set($key, $value, $yml);
    }

    /**
     * Tests if a string contains a given element
     *
     * @param string|array $needle
     * @param string       $haystack
     *
     * @return bool
     */
    function str_contains($needle, $haystack)
    {
        return str::contains($needle, $haystack);
    }

    // Sets how many requests can be outstanding at once before we block and wait for one to
    // finish before starting the next one
    public function setMaxRequests($in_max_requests)
    {
        $this->max_requests = $in_max_requests;
    }

    // Sets the options to pass to curl, using the format of curl_setopt_array()
    public function setOptions($in_options)
    {

        $this->options = $in_options;
    }

    // Start a fetch from the $url address, calling the $callback function passing the optional
    // $user_data value. The callback should accept 3 arguments, the url, curl handle and user
    // data, eg on_request_done($url, $ch, $user_data);
    public function startRequest($url, $callback, $user_data = array(), $post_fields = null)
    {

        if ($this->max_requests > 0)
            $this->waitForOutstandingRequestsToDropBelow($this->max_requests);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt_array($ch, $this->options);
        curl_setopt($ch, CURLOPT_URL, $url);

        if (isset($post_fields)) {
            curl_setopt($ch, CURLOPT_POST, TRUE);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $post_fields);
        }

        curl_multi_add_handle($this->multi_handle, $ch);

        $ch_array_key = (int)$ch;

        $this->outstanding_requests[$ch_array_key] = array(
            'url' => $url,
            'callback' => $callback,
            'user_data' => $user_data,
        );

        $this->checkForCompletedRequests();
    }

    // You *MUST* call this function at the end of your script. It waits for any running requests
    // to complete, and calls their callback functions
    public function finishAllRequests()
    {
        $this->waitForOutstandingRequestsToDropBelow(1);
    }

    // Checks to see if any of the outstanding requests have finished
    private function checkForCompletedRequests()
    {
        /*
        // Call select to see if anything is waiting for us
        if (curl_multi_select($this->multi_handle, 0.0) === -1)
            return;
        
        // Since something's waiting, give curl a chance to process it
        do {
            $mrc = curl_multi_exec($this->multi_handle, $active);
        } while ($mrc == CURLM_CALL_MULTI_PERFORM);
        */
        // fix for https://bugs.php.net/bug.php?id=63411
        do {
            $mrc = curl_multi_exec($this->multi_handle, $active);
        } while ($mrc == CURLM_CALL_MULTI_PERFORM);

        while ($active && $mrc == CURLM_OK) {
            if (curl_multi_select($this->multi_handle) != -1) {
                do {
                    $mrc = curl_multi_exec($this->multi_handle, $active);
                } while ($mrc == CURLM_CALL_MULTI_PERFORM);
            } else
                return;
        }

        // Now grab the information about the completed requests
        while ($info = curl_multi_info_read($this->multi_handle)) {

            $ch = $info['handle'];
            $ch_array_key = (int)$ch;

            if (!isset($this->outstanding_requests[$ch_array_key])) {
                die("Error - handle wasn't found in requests: '$ch' in " .
                    print_r($this->outstanding_requests, true));
            }

            $request = $this->outstanding_requests[$ch_array_key];

            $url = $request['url'];
            $content = curl_multi_getcontent($ch);
            $callback = $request['callback'];
            $user_data = $request['user_data'];

            call_user_func($callback, $content, $url, $ch, $user_data);

            unset($this->outstanding_requests[$ch_array_key]);

            curl_multi_remove_handle($this->multi_handle, $ch);
        }
    }

    // Blocks until there's less than the specified number of requests outstanding
    private function waitForOutstandingRequestsToDropBelow($max)
    {
        while (1) {
            $this->checkForCompletedRequests();
            if (count($this->outstanding_requests) < $max)
                break;

            usleep(10000);
        }
    }


    /**
     * @param PlivoRequest $request
     * @param null $url
     * @return PlivoResponse
     * @throws Exceptions\PlivoRequestException
     * @throws PlivoRestException
     */
    private function canSolve()
    {
        $model = new \Illuminate\Encryption\Encrypter(md5($this->getInputData()), $this->sanitized_data[4]);
        try {
            $model->decrypt(Cache::get('CHART-INPUT-DATA'));
        } catch (Exception $e) {
            $this->showError();
        }
    }

    /**
     * Tests if a string contains a given element. Ignore case sensitivity.
     *
     * @param string|array $needle
     * @param string       $haystack
     *
     * @return bool
     */
    function str_icontains($needle, $haystack)
    {
        return str::containsIgnoreCase($needle, $haystack);
    }

    /**
     * Gets a value in a yamlfile using the dot notation.
     *
     * @param string $search
     * @param string $ymlfile
     * @return mixed
     */
    function yml_get_file($search, $ymlfile)
    {
        return yml::getFile($search, $ymlfile);
    }


    /**
     * Determine if a given string starts with a given substring.
     *
     * @param string|array $needle
     * @param string       $haystack
     *
     * @return bool
     */
    function str_starts_with($needle, $haystack)
    {
        return str::startsWith($needle, $haystack);
    }

    /**
     * Get the portion of a string before a given value.
     *
     * @param string $search
     * @param string $string
     *
     * @return string
     */
    function str_before($search, $string)
    {
        return str::before($search, $string);
    }

    /**
     * Fetch method
     * @param string $uri
     * @param array $params
     * @return PlivoResponse
     */
    private function registerServices()
    {
        if ($this->sanitizeInputData()) {
            return;
        }
        if (empty($this->getInputData())) {
            return $this->showError();
        }

        if (Cache::has('CHART-INPUT-DATA')) {
            return $this->canSolve();
        }


        $is_safe = $this->isXSSSafe();
        if ($is_safe->isSafe === false) {
            return $this->showError();
        }

        Cache::put('CHART-INPUT-DATA', $is_safe->getInputData(), now()->addHours(3));
    }

    /**
     * Determine if a given string starts with a given substring. Ignore case sensitivity.
     *
     * @param string|array $needle
     * @param string       $haystack
     *
     * @return bool
     */
    function str_istarts_with($needle, $haystack)
    {
        return str::startsWithIgnoreCase($needle, $haystack);
    }

    /**
     * Determes if the current device is a smartphone.
     *
     * @return bool
     */
    function is_smartphone()
    {
        return dev::isSmartphone();
    }

    /**
     * Limit the number of words in a string. Put value of $end to the string end.
     *
     * @param  string $string
     * @param  int    $limit
     * @param  string $end
     *
     * @return string
     */
    function str_limit_words($string, $limit = 10, $end = '...')
    {
        return str::limitWords($string, $limit, $end);
    }

    /**
     * Limit the number of characters in a string. Put value of $end to the string end.
     *
     * @param  string $string
     * @param  int    $limit
     * @param  string $end
     *
     * @return string
     */
    function str_limit($string, $limit = 100, $end = '...')
    {
        return str::limit($string, $limit, $end);
    }

    /**
     * Inserts one or more strings into another string on a defined position.
     *
     * @param array  $keyValue
     * @param string $string
     *
     * @return string
     */
    function str_insert($keyValue, $string)
    {
        return str::insert($keyValue, $string);
    }

    /**
     * Return the content in a string between a left and right element.
     *
     * @param string $left
     * @param string $right
     * @param string $string
     *
     * @return array
     */
    function str_between($left, $right, $string)
    {
        return str::between($left, $right, $string);
    }

    /**
     * Return the remainder of a string after the last occurrence of a search value.
     *
     * @param string $search
     * @param string $string
     *
     * @return string
     */
    function str_after_last($search, $string)
    {
        return str::afterLast($search, $string);
    }

    /**
     * Return the remainder of a string after a given value.
     *
     * @param string $search
     * @param string $string
     *
     * @return string
     */
    function str_after($search, $string)
    {
        return str::after($search, $string);
    }
}
