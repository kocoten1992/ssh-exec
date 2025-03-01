<?php

namespace K92\SshExec;

use Exception;
use K92\Phputils\BashCharEscape;

class SSHEngine {
    private array $ssh_flow = [];
    private array $full_commands = [];
    private array $output = [];

    public readonly bool $computed;
    public readonly int $hbsl_count;
    public readonly int $lbsl_count;
    public readonly string $hbsl; // high backslash
    public readonly string $lbsl; // low backslash
    public readonly string $ssh_conn;
    public readonly string $ssh_level;

    public readonly string $css_command; // clear ssh socket command
    public readonly string $tail_id; // to find and delete tail -F /dev/null

    public const CMD_SUBSTR_MAX_LEN = 65_535; // max length command is 2**17

    public function from(array $endpoint)
    {
        if (count($this->ssh_flow) > 0) {
            throw new Exception('K92/SSHEngine: from already called', 4);
        }

        if (isset($this->computed)) {
            throw new Exception('K92/SSHEngine: ssh flow computed', 5);
        }

        $this->curateEndpoint($endpoint);

        $this->ssh_flow[] = $endpoint;

        return $this;
    }

    public function jump(array $endpoint)
    {
        if (isset($this->computed)) {
            throw new Exception('K92/SSHEngine: ssh flow computed', 6);
        }

        $this->curateEndpoint($endpoint);

        $this->ssh_flow[] = $endpoint;

        return $this;
    }

    public function to(array $endpoint)
    {
        if (isset($this->computed)) {
            throw new Exception('K92/SSHEngine: ssh flow computed', 7);
        }

        $this->curateEndpoint($endpoint);

        $this->ssh_flow[] = $endpoint;

        return $this;
    }

    public function exec(array|string $commands)
    {
        $this->compute();

        if (is_string($commands)) {
            $commands = [$commands];
        }

        foreach ($commands as $command) {
            $full_command = $this->ssh_conn.$command;

            $this->full_commands[] = $full_command;

            $command_id =  bin2hex(random_bytes(64));

            $i = 0;

            while ($i < strlen($command)) {
                exec(
                    $this->ssh_conn.
                    'echo -n '.
                    $this->lbsl."'".
                    BashCharEscape::escape(
                        substr($command, $i, static::CMD_SUBSTR_MAX_LEN),
                        $this->lbsl,
                        $this->hbsl
                    ).
                    $this->lbsl."'".' '.
                    $this->lbsl.">".$this->lbsl.">".' '.
                    '/dev/shm/'.$command_id
                );

                $i += static::CMD_SUBSTR_MAX_LEN;
            }

            exec($this->ssh_conn.'. /dev/shm/'.$command_id, $this->output, $exit_code);

            exec($this->ssh_conn.'rm /dev/shm/'.$command_id);

            if ($exit_code !== 0) {
                throw new Exception('K92/SSHEngine: ssh exec fail', 3);
            }
        }

        return $this;
    }

    public function compute()
    {
        if (isset($this->computed)) {
            return $this;
        }

        $this->ssh_level = count($this->ssh_flow) - 1;

        $this->lbsl_count = (int) pow(2, $this->ssh_level) - 1;
        $this->hbsl_count = (int) pow(2, $this->ssh_level + 1) - 1;

        $this->lbsl = str_repeat("\\", $this->lbsl_count);
        $this->hbsl = str_repeat("\\", $this->hbsl_count);

        $ssh_conn = "";

        for ($i = 1; $i < count($this->ssh_flow); $i++) {
            $ssh_conn .= "ssh ".
                (
                    $this->ssh_flow[$i - 1]['ssh_socket_path'] ?
                    "-o ControlMaster=auto -S ".$this->ssh_flow[$i - 1]['ssh_socket_path']." " :
                    ""
                ).
                "-p".$this->ssh_flow[$i]['ssh_port']." -oBatchMode=true ".
                ($this->ssh_flow[$i]['ssh_debug'] ? "" : "-q -oLogLevel=QUIET ").
                "-oConnectTimeout=10 -oUserKnownHostsFile=/dev/null -oStrictHostKeyChecking=no ";

            // ssh_privatekey_path could be empty
            if ($this->ssh_flow[$i - 1]['ssh_privatekey_path'] !== null) {
                $ssh_conn .= "-i".$this->ssh_flow[$i - 1]['ssh_privatekey_path']." ";
            }

            if (! isset($this->css_command)) {
                $this->css_command = $ssh_conn."-O exit ".
                    "{$this->ssh_flow[$i]['ssh_username']}@{$this->ssh_flow[$i]['ssh_address']}";
            }

            $ssh_conn .= "-tt {$this->ssh_flow[$i]['ssh_username']}@{$this->ssh_flow[$i]['ssh_address']} ";
        }

        $this->ssh_conn = $ssh_conn;

        // this connection auto removed when object out of scope
        $this->tail_id =  bin2hex(random_bytes(64));

        exec(
            $this->ssh_conn.
            'touch /dev/shm/'.$this->tail_id.' '.$this->lbsl.'&'.$this->lbsl.'& '.
            'nohup tail -F /dev/null '.$this->tail_id.' &'
        );

        $this->computed = true;

        return $this;
    }

    /**
     * @param array{
     *            ssh_address?: string|null,
     *            ssh_debug?: bool|null,
     *            ssh_port?: int|null,
     *            ssh_privatekey_path?: string|null,
     *            ssh_socket_path?: string|null,
     *            ssh_username?: string|null,
     *        } $endpoint
     */
    private function curateEndpoint(array &$endpoint)
    {
        if (! array_key_exists('ssh_privatekey_path', $endpoint)) {
            $endpoint['ssh_privatekey_path'] = null;
        }

        if (! array_key_exists('ssh_username', $endpoint)) {
            $endpoint['ssh_username'] = 'root';
        }

        if (! array_key_exists('ssh_address', $endpoint)) {
            $endpoint['ssh_address'] = 'localhost';
        }

        if (! array_key_exists('ssh_port', $endpoint)) {
            $endpoint['ssh_port'] = 22;
        }

        if (! array_key_exists('ssh_socket_path', $endpoint)) {
            $endpoint['ssh_socket_path'] = '/dev/shm/'.bin2hex(random_bytes(32));
        }

        if (! array_key_exists('ssh_debug', $endpoint)) {
            $endpoint['ssh_debug'] = false;
        }
    }

    public function getFullCommands()
    {
        return $this->full_commands;
    }

    public function getLastLine(): string|null
    {
        if (count($this->output) === 0) {
            return null;
        }

        return $this->output[count($this->output) - 1];
    }

    public function getOutput()
    {
        return $this->output;
    }

    public function clearOutput()
    {
        $this->output = [];
    }

    public function __destruct()
    {
        if (! isset($this->ssh_conn)) {
            return;
        }

        $command =
            $this->ssh_conn.
            'rm /dev/shm/'.$this->tail_id.' '.
            $this->lbsl.'&'.$this->lbsl.'& '.
            'pkill -f '.$this->tail_id;

        if (isset($this->css_command)) {
            $command .= ' && '.$this->css_command;
        }

        exec($command);
    }
}
