<?php

/** This class can do certain imap commands, including custom commands, search etc.
 * Class imap_driver
 */
class imap_driver
{
    private $command_counter = "00000001";

    private $fp;
    public $error;

    public $last_response = array();
    public $last_endline = "";

    private $full_debug = false;

    public function init($host, $port)
    {
        if (!($this->fp = fsockopen($host, $port, $errno, $errstr, 15))) {
            $this->error = "Could not connect to host ($errno) $errstr";

            return false;
        }

        if (!stream_set_timeout($this->fp, 15)) {
            $this->error = "Could not set timeout";

            return false;
        }

        $line = fgets($this->fp);
        if ($this->full_debug) {
            echo $line;
        }

        return true;
    }


    public function login($login, $pwd)
    {
        $this->command("LOGIN $login $pwd");

        if (preg_match('~^OK~', $this->last_endline)) {
            $this->error = join(', ', $this->last_response);

            return true;
        } else {
            $this->close();

            return false;
        }
    }

    public function select_folder($folder)
    {
        $this->command("SELECT $folder");

        if (preg_match('~^OK~', $this->last_endline)) {
            return true;
        } else {
            $this->close();

            return false;
        }
    }

    public function get_uids_by_search($criteria)
    {
        $this->command("SEARCH $criteria");

        if (preg_match('~^OK~', $this->last_endline) && is_array($this->last_response) && count($this->last_response) == 1) {
            $splitted_response = explode(' ', $this->last_response[0]);
            $uids              = array();

            foreach ($splitted_response as $item) {
                if (preg_match('~^\d+$~', $item)) {
                    $uids[] = $item;
                }
            }

            return $uids;
        } else {
            $this->close();

            return false;
        }
    }

    public function get_headers_from_uid($uid)
    {
        $this->command("FETCH $uid BODY.PEEK[HEADER]");

        if (preg_match('~^OK~', $this->last_endline)) {
            array_shift($this->last_response); // skip first line

            $headers    = array();
            $prev_match = '';
            foreach ($this->last_response as $item) {

                if (preg_match('~^([a-z][a-z0-9-_]+):~is', $item, $match)) {
                    $header_name           = strtolower($match[1]);
                    $prev_match            = $header_name;
                    $headers[$header_name] = trim(substr($item, strlen($header_name) + 1));
                } else {
                    $headers[$prev_match] .= " " . $item;
                }
            }

            return $headers;
        } else {
            $this->close();

            return false;
        }
    }

    private function command($command)
    {
        $this->last_response = array();
        $this->last_endline  = "";

        if ($this->full_debug) {
            echo "$this->command_counter $command\r\n";
        }

        fwrite($this->fp, "$this->command_counter $command\r\n");

        while ($line = fgets($this->fp)) {
            $line = trim($line); // do not combine with the line above in while loop, because sometimes valid response maybe \n

            if ($this->full_debug) {
                echo "Line: [$line]\n";
            }

            $line_arr = preg_split('/\s+/', $line, 0, PREG_SPLIT_NO_EMPTY);
            if (count($line_arr) > 0) {
                $code = array_shift($line_arr);
                if (strtoupper($code) == $this->command_counter) {
                    $this->last_endline = join(' ', $line_arr);
                    break;
                } else {
                    $this->last_response[] = $line;
                }
            } else {
                $this->last_response[] = $line;
            }
        }

        $this->increment_counter();
    }

    private function increment_counter()
    {
        $this->command_counter = sprintf('%08d', intval($this->command_counter) + 1);
    }

    public function close()
    {
        fclose($this->fp);
    }
}