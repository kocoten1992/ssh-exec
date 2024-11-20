<?php

namespace K92\SshExec;

use Exception;

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

    public function from(array $endpoint)
    {
        $this->curateEndpoint($endpoint);

        $this->ssh_flow[] = $endpoint;

        return $this;
    }

    public function jump(array $endpoint)
    {
        $this->curateEndpoint($endpoint);

        $this->ssh_flow[] = $endpoint;

        return $this;
    }

    public function to(array $endpoint)
    {
        $this->curateEndpoint($endpoint);

        $this->ssh_flow[] = $endpoint;

        return $this;
    }

    public function exec(string $command, array $options = [])
    {
        $this->compute();

        $full_command = $this->ssh_conn.$command;

        $this->full_commands[] = $full_command;

        exec($full_command, $this->output, $exit_code);

        if ($exit_code !== 0) {
            throw new Exception('K92/SSHEngine: ssh exec fail', 3);
        }

        return $this;
    }

    public function compute()
    {
        if (isset($this->computed)) {
            return;
        }

        $this->ssh_level = count($this->ssh_flow) - 1;

        $this->lbsl_count = (int) pow(2, $this->ssh_level) - 1;
        $this->hbsl_count = (int) pow(2, $this->ssh_level + 1) - 1;

        $this->lbsl = str_repeat("\\", $this->lbsl_count);
        $this->hbsl = str_repeat("\\", $this->hbsl_count);

        $ssh_conn = "";

        for ($i = 1; $i < count($this->ssh_flow); $i++) {
            $ssh_conn .= "ssh ".
                ($this->ssh_flow[$i]['ssh_socket_path'] ? "-S ".$this->ssh_flow[$i]['ssh_socket_path']." " : "").
                "-p".$this->ssh_flow[$i]['ssh_port']." -oBatchMode=true ".
                ($this->ssh_flow[$i]['ssh_debug'] ? "" : "-q -oLogLevel=QUIET ").
                "-oConnectTimeout=10 -oUserKnownHostsFile=/dev/null -oStrictHostKeyChecking=no ";

            // ssh_privatekey_path could be empty
            if ($this->ssh_flow[$i - 1]['ssh_privatekey_path'] !== null) {
                $ssh_conn .= "-i".$this->ssh_flow[$i - 1]['ssh_privatekey_path']." ";
            }

            $ssh_conn .= "-tt {$this->ssh_flow[$i]['ssh_username']}@{$this->ssh_flow[$i]['ssh_address']} ";
        }

        $this->ssh_conn = $ssh_conn;

        $this->computed = true;
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
            $endpoint['ssh_socket_path'] = null;
        }

        if (! array_key_exists('ssh_debug', $endpoint)) {
            $endpoint['ssh_debug'] = false;
        }
    }

    public function getFullCommands()
    {
        return $this->full_commands;
    }

    public function getOutput()
    {
        return $this->output;
    }
}
