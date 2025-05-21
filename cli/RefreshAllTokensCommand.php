<?php

namespace Grav\Plugin\Console;

use Grav\Common\Grav;
use Grav\Common\File\CompiledYamlFile;
use Grav\Console\ConsoleCommand;
use GuzzleHttp\Client;
use Symfony\Component\Console\Input\InputArgument;

class RefreshAllTokensCommand extends ConsoleCommand
{
    protected static $grav;

    public function __construct($name = null)
    {
        parent::__construct($name);
        self::$grav = Grav::instance();
    }

    /**
     * {@inherit}
     */
    protected function configure()
    {
        $this
            ->setName('refresh:all-tokens')
            ->setDescription('Attempts to refresh access tokens for all supported networks (Facebook, Instagram, Twitter).')
            ->addOption(
                'app_id',
                'id',
                InputArgument::OPTIONAL,
                'The App ID'
            )
            ->addOption(
                'app_secret',
                'secret',
                InputArgument::OPTIONAL,
                'The App Secret'
            );
    }

    /**
     * {@inherit}
     */
    protected function serve()
    {
        require_once __DIR__.'/../vendor/autoload.php';

        $config = self::$grav['config']->get('plugins.social-feed');
        $config['app_id'] = $this->input->getOption('app_id');
        $config['app_secret'] = $this->input->getOption('app_secret');

        // Liste von unterstützten Netzwerken
        $networks = ['facebook', 'instagram'];

        foreach ($networks as $network) {
            $this->output->writeln("Attempting to refresh token for: <yellow>$network</yellow>");

            switch ($network) {
                case 'facebook':
                    foreach ($config['facebook_feeds'] as $feed) {
                        $this->refreshFacebookToken($feed, $config);
                    }
                    break;


                case 'instagram':
                    foreach ($config['instagram_feeds'] as $feed) {
                        $this->refreshInstagramToken($feed, $config);
                    }
                    break;

                default:
                    $this->error("Unsupported network: $network");
            }
        }

        $this->success('All token refresh attempts completed.');
    }

    private function graphApiRefresh($config, $apiVersion, $clientId, $clientSecret, $accesstoken, $network, $feed)
    {
        $arrContextOptions = array();

        if($config['enablessl'] === false) {
            $arrContextOptions['ssl']['verify_peer'] = false;
            $arrContextOptions['ssl']['verify_peer_name'] = false;
        }

        if(isset($config['certpath']) && !empty($config['certpath'])) {
            $arrContextOptions['ssl']['cafile'] = $config['certpath'];
        }

        $url = 'https://graph.facebook.com/v' . $apiVersion . '/oauth/access_token';
        $url .= '?grant_type=fb_exchange_token';
        $url .= '&client_id=' . $clientId;
        $url .= '&client_secret=' . $clientSecret;
        $url .= '&fb_exchange_token=' . $accesstoken;

        try {
            $response = file_get_contents($url, false, stream_context_create($arrContextOptions));

            if ($response) {
                $response = json_decode($response);
                if (!empty($response->access_token)) {
                    $this->updateConfig($network, $feed, $response->access_token);
                    $this->success("{$network} token has been refreshed.");
                } else {
                    $this->error("Failed to refresh {$network} token: No access token in response.");
                }
            } else {
                $this->error("Failed to refresh {$network} token: No Response.");
            }
        } catch (Exception $e) {
            $this->error("Error refreshing {$network} token: " . $e->getMessage());
        }

    }

    private function refreshFacebookToken($feed, $config)
    {
        if (!isset($feed['accesstoken']) || !isset($config['app_id']) || !isset($config['app_secret']) || !isset($config['facebook_api_version'])) {
            $this->error('Facebook configuration is incomplete.');
            return;
        }

        $accesstoken = $feed['accesstoken'];
        $clientId = $config['app_id'];
        $clientSecret = $config['app_secret'];
        $apiVersion = $config['facebook_api_version'];

        $this->graphApiRefresh($config, $apiVersion, $clientId, $clientSecret, $accesstoken, 'facebook', $feed);
    }

    private function refreshInstagramToken($feed, $config)
    {
        if (!isset($feed['access_token']) || !isset($config['app_id']) || !isset($config['app_secret']) || !isset($config['instagram_api_version'])) {
            $this->error('Instagram configuration is incomplete.');
            return;
        }

        $accesstoken = $feed['access_token'];
        $apiVersion = $config['instagram_api_version'];
        $clientId = $config['app_id'];
        $clientSecret = $config['app_secret'];

        $this->graphApiRefresh($config, $apiVersion, $clientId, $clientSecret, $accesstoken, 'instagram', $feed);
    }

    private function updateConfig($network, $feed, $value)
    {
        $configFile = CompiledYamlFile::instance('user/config/plugins/social-feed.yaml');
        $configData = $configFile->content();

        if ($network === 'facebook') {
            $userKey = 'username';
            $networkKey = 'facebook_feeds';
            $accessTokenKey = 'accesstoken';
        } else if ($network === 'instagram') {
            $userKey = 'userid';
            $networkKey = 'instagram_feeds';
            $accessTokenKey = 'access_token';
        } else {
            return;
        }

        foreach ($configData[$networkKey] as &$configFeed) {
            if ($configFeed[$userKey] === $feed[$userKey]) {
                $oldToken = $configFeed[$accessTokenKey];
                $configFeed[$accessTokenKey] = $value;
                break;
            }
        }

        if (isset($oldToken) && !empty($oldToken)) {
            foreach ($configData['facebook_feeds'] as &$configFeed) {
                if ($configFeed['accesstoken'] === $oldToken) {
                    $configFeed['accesstoken'] = $value;
                    break;
                }
            }

            foreach ($configData['instagram_feeds'] as &$configFeed) {
                if ($configFeed['access_token'] === $oldToken) {
                    $configFeed['access_token'] = $value;
                    break;
                }
            }
        }

        $configFile->save($configData);
        $configFile->free();
    }

    /**
     * Notify error.
     *
     * @param string $message
     */
    protected function error($message)
    {
        self::$grav['log']->error($message);
        $this->output->writeln('<red>' . $message . '</red>');
    }

    /**
     * Notify success.
     *
     * @param string $message
     */
    protected function success($message)
    {
        $this->output->writeln('<green>' . $message . '</green>');
    }
}
