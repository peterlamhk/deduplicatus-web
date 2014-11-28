<?php

class GoogleDrive implements CloudStorage {
    private $vault;
    private $identifier;
    private $client;
    private $services;
    private $drive;

    /*
     * Constructor
     */
    public function __construct($vault = NULL, $identifier = '') {
        global $_CONF;

        $this->vault = $vault;
        $this->identifier = $identifier;

        $client = new Google_Client();
        $client->setClientId($_CONF['cloud']['googledrive']['credentials']['client']);
        $client->setClientSecret($_CONF['cloud']['googledrive']['credentials']['secret']);
        $client->setRedirectUri($_CONF['cloud']['googledrive']['redirectUri']);
        $client->setAccessType('offline');
        $client->setScopes($_CONF['cloud']['googledrive']['scopes']);
        $client->setApprovalPrompt('force'); // to ensure api give us the refresh_token

        $this->client = $client;
    }

    public function loadAccessToken($token) {
        $this->client->setAccessToken($token);

        $json = json_decode($token);
        if( time() > ($json->created + $json->expires_in) ) {
            $this->client->refreshToken($json->refresh_token);

            // save token to metafile
            $this->vault->updateAccessToken($this->identifier, $this->client->getAccessToken());
        }

        $this->drive = new Google_Service_Drive($this->client);
    }

    public function createAuthUrl($vaultId) {
        global $_CONF;

        $authorizeUrl = $this->client->createAuthUrl();

        $this->vault = $vaultId;
        $_SESSION['oauth_vault'] = $this->vault;
        return $authorizeUrl;
    }

    public function oauthCallback() {
        $this->vault = ( !empty($_SESSION['oauth_vault']) ) ? $_SESSION['oauth_vault'] : '';

        $accessToken = $this->client->authenticate($_GET['code']);
        $json        = json_decode($accessToken);

        $response = array(
            'success'      => false,
            'vault'        => $this->vault,
            'type'         => 'googledrive',
            'error_msg'    => '',
            'access_token' => '',
            'email'        => '',
            'name'         => '',
            'quota'        => 0,
            );

        if( !empty($json->access_token) ) {
            $this->client->setAccessToken($accessToken);

            $this->services = new Google_Service_Drive($this->client);
            $about = $this->services->about->get();
            $response = array(
                'success'      => true,
                'vault'        => $this->vault,
                'type'         => 'googledrive',
                'error_msg'    => '',
                'access_token' => $accessToken,
                'email'        => $about->user->emailAddress,
                'name'         => $about->name,
                'quota'        => intval($about->quotaBytesTotal),
                );
        }

        return $response;
    }

    public function listFile($path = "/") {
        $list      = array();
        $result    = array();
        $pageToken = NULL;

        $parents = "root";

        do {
            $parameters = array();
            $parameters = array('q' => "'{$parents}' in parents and trashed = false");
            if( $pageToken ) {
                $parameters['pageToken'] = $pageToken;
            }
            $files = $this->drive->files->listFiles($parameters);
            $results = $files->getItems();

            foreach ($results as $value) {
                $list[] = array(
                    'size'      => ( !empty($value->fileSize) ) ? $value->fileSize : 0,
                    'name'      => ( !empty($value->originalFilename) ) ? $value->originalFilename : $value->title,
                    'path'      => '/' . (( !empty($value->originalFilename) ) ? $value->originalFilename : $value->title),
                    'modified'  => strtotime($value->modifiedDate),
                    'is_folder' => ( $value->mimeType == 'application/vnd.google-apps.folder' ),
                    'cloud'     => $this->identifier,
                    );
            }

            $pageToken = $files->getNextPageToken();
        } while( $pageToken );

        return $list;
    }

}
