# SSH EXEC

Helper tool to generate ssh command line

## Usage

```php
use K92\SshExec\SSHEngine;

$se = (new SSHEngine)
    ->from([
        'ssh_privatekey_path' => '/root/.ssh/id_ed25519', 
    ])
    ->to([
        'ssh_address' => 'localhost',
        'ssh_debug' => true,
        'ssh_port' => 22,
        'ssh_socket_path' => null, // multiplexing
        'ssh_username' => 'root',
    ])
    ->exec('ls -1 /opt/')
    ->exec('touch /opt/newfile');
```

## Installation

```bash
composer require k92/ssh-exec
```

## Testing

```php
./vendor/bin/phpunit tests
```

## License
[MIT](https://choosealicense.com/licenses/mit/)
