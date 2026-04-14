<?php
// Mock Psr interfaces for testing
namespace Psr\Http\Client {
    interface ClientInterface { public function sendRequest($request); }
    interface ClientExceptionInterface extends \Throwable {}
}
namespace Psr\Http\Message {
    interface RequestFactoryInterface { public function createRequest($method, $uri); }
    interface StreamFactoryInterface { public function createStream($content); }
}
namespace Psr\Log {
    interface LoggerInterface { 
        public function emergency($m); public function alert($m); public function critical($m);
        public function error($m); public function warning($m); public function notice($m);
        public function info($m); public function debug($m); public function log($l, $m);
    }
}
namespace {
    define('TESTING', true);
    
    function require_e($path) {
        $abs = __DIR__ . '/' . $path;
        if (file_exists($abs)) {
            require_once $abs;
        } else {
            echo "Missing file: $abs\n";
        }
    }

    require_e('src/SiegAuth/Exceptions/SiegAuthException.php');
    require_e('src/SiegAuth/Exceptions/SiegHttpException.php');
    require_e('src/SiegAuth/SiegOAuthOptions.php');
    require_e('src/SiegAuth/SiegToken.php');
    require_e('src/SiegAuth/SiegTokenStoreInterface.php');
    require_e('src/SiegAuth/InMemorySiegTokenStore.php');
    require_e('src/SiegAuth/SiegIntegrationClient.php');

    use SiegAuth\SiegOAuthOptions;
    use SiegAuth\SiegIntegrationClient;
    use SiegAuth\InMemorySiegTokenStore;

    $options = new SiegOAuthOptions([
        'clientId'    => 'my-client',
        'secretKey'   => 'my-secret',
        'redirectUri' => 'https://callback'
    ]);

    $client = new class implements \Psr\Http\Client\ClientInterface {
        public function sendRequest($request) { return null; }
    };
    $requestFactory = new class implements \Psr\Http\Message\RequestFactoryInterface {
        public function createRequest($method, $uri) { return null; }
    };
    $streamFactory = new class implements \Psr\Http\Message\StreamFactoryInterface {
        public function createStream($content) { return null; }
    };

    $sieg = new SiegIntegrationClient(
        $client, $requestFactory, $streamFactory, $options, new InMemorySiegTokenStore()
    );

    $url = $sieg->getAuthorizationUrl('mystate', 'read');
    
    echo "Teste concluido. URL Gerada com sucesso: " . $url . "\n";
}
