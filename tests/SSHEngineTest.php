<?php

use PHPUnit\Framework\TestCase;

use K92\SshExec\SSHEngine;

class SSHEngineTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();

        exec('podman --version 2>&1', $output, $exit_code);

        if ($exit_code !== 0) {
            $this->markTestIncomplete('Need podman to complete this test.');
        }

        exec('podman image exists ssh-exec', $output, $exit_code);

        if ($exit_code !== 0) {
            exec('podman build -t ssh-exec -f '.__DIR__.'/Dockerfile .', $output, $exit_code);

            if ($exit_code !== 0) {
                $this->markTestIncomplete('Unable build podman ssh-exec image.');
            }
        }
    }

    public function test_ssh_case_1()
    {
        /**
         * a simple ssh command to run "ls -1"
         * A --ssh--> B: ls -1
         */


        // generate ssh key for A to connect to B
        $container_name = 'ssh_exec_test_ssh_case_1';

        $ssh_privatekey_path = __DIR__.'/id_ed25519_test_ssh_case_1';

        $this->assertFileDoesNotExist($ssh_privatekey_path);
        $this->assertFileDoesNotExist($ssh_privatekey_path.'.pub');

        exec(
            'ssh-keygen -N "" -t ed25519 -C "'.$container_name.'" -f '.$ssh_privatekey_path,
            $output,
            $exit_code
        );

        $this->assertEquals(0, $exit_code);

        $this->assertFileExists($ssh_privatekey_path);
        $this->assertFileExists($ssh_privatekey_path.'.pub');


        // create podman container
        exec(
            'podman run --rm -d --name '.$container_name.' -p 22 ssh-exec',
            $output,
            $exit_code
        );

        $this->assertEquals(0, $exit_code);

        exec('podman container exists '.$container_name, $output, $exit_code);

        $this->assertEquals(0, $exit_code);


        // get ssh_port
        $output = [];

        exec('podman port '.$container_name.' 22', $output, $exit_code);

        $this->assertEquals(0, $exit_code);

        $ssh_port = parse_url($output[0])['port'];


        // copy ssh public key to authorized_keys
        $exec = exec(
            'podman exec '.$container_name.' '.
            'sh -c \'echo "'.file_get_contents($ssh_privatekey_path.'.pub').'" >> /root/.ssh/authorized_keys\'',
            $output,
            $exit_code
        );

        $this->assertEquals(0, $exit_code);


        // touch a file in podman container
        $filename = bin2hex(random_bytes(16));

        $exec = exec('podman exec '.$container_name.' ls -1 /opt/', $output, $exit_code);

        $this->assertEquals(0, $exit_code);

        $this->assertEquals("", $exec);

        $exec = exec('podman exec '.$container_name.' touch /opt/'.$filename, $output, $exit_code);

        $this->assertEquals(0, $exit_code);

        $exec = exec('podman exec '.$container_name.' ls -1 /opt/', $output, $exit_code);

        $this->assertEquals($exec, $filename); // file do exists


        // test ssh command can achieve the same
        $se = (new SSHEngine)
            ->from([
                'ssh_privatekey_path' => $ssh_privatekey_path, 
            ])
            ->to([
                'ssh_address' => 'localhost',
                'ssh_debug' => (bool) rand(0, 1),
                'ssh_port' => $ssh_port,
                'ssh_socket_path' => null,
                'ssh_username' => 'root',
            ])
            ->exec('ls -1 /opt/');

        $this->assertEquals($se->output[0], $filename);


        // clean up
        exec('podman kill '.$container_name, $output, $exit_code);

        $this->assertEquals(0, $exit_code);

        exec('podman container exists '.$container_name, $output, $exit_code);

        $this->assertNotEquals(0, $exit_code);

        // remove ssh key
        $this->assertFileExists($ssh_privatekey_path);
        $this->assertFileExists($ssh_privatekey_path.'.pub');

        unlink($ssh_privatekey_path);
        unlink($ssh_privatekey_path.'.pub');

        $this->assertFileDoesNotExist($ssh_privatekey_path);
        $this->assertFileDoesNotExist($ssh_privatekey_path.'.pub');
    }
}
