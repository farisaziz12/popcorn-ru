<?php

namespace App\Spider;

use App\Entity\Movie;
use App\Entity\Torrent\BaseTorrent;
use App\Entity\Torrent\MovieTorrent;
use App\Service\EpisodeService;
use App\Service\TorrentService;
use App\Spider\Dto\ForumDto;
use App\Spider\Dto\TopicDto;
use GuzzleHttp\Client;
use GuzzleHttp\RequestOptions;
use Psr\Log\LoggerInterface;

class Yts extends AbstractSpider
{
    public const BASE_URL = 'https://yts.mx/';

    /** @var Client */
    private $client;

    public function __construct(TorrentService $torrentService, EpisodeService $episodeService, LoggerInterface $logger)
    {
        parent::__construct($torrentService, $episodeService, $logger);
        $this->client = new Client([
            'base_uri' => self::BASE_URL,
            RequestOptions::TIMEOUT => 10,
        ]);
    }

    public function getPriority(BaseTorrent $torrent): int
    {
        if ($torrent->getLanguage() === 'en') {
            return 10;
        }
        return parent::getPriority($torrent);
    }

    public function getForumKeys(): array
    {
        return [1];
    }

    public function getPage(ForumDto $forum): \Generator
    {
        $res = $this->client->get('/api/v2/list_movies.json', [
            'query' => [
                'limit' => 50,
                'page' => $forum->page,
            ]
        ]);
        $json = $res->getBody()->getContents();
        $data = json_decode($json, true);

        if (empty($data['data']['movies'])) {
            return ;
        }

        $after = $forum->last ? new \DateTime($forum->last.' hours ago') : false;
        if (!$after || $this->hasNewTorrents($data, $after)) {
            yield new ForumDto($forum->id, $forum->page + 1, $forum->last, random_int(1800, 3600));
        }

        foreach ($data['data']['movies'] as $movieData) {
            $media = $this->torrentService->getMediaByImdb($movieData['imdb_code']);
            if (!($media instanceof Movie)) {
                continue;
            }
            foreach($movieData['torrents'] as $torrentData) {
                $this->buildTorrentFromData($media, $movieData, $torrentData);
            }
        }
    }

    private function hasNewTorrents($data, \DateTime $after): bool
    {
        foreach ($data['data']['movies'] as $movieData) {
            foreach($movieData['torrents'] as $torrentData) {
                if ($after->getTimestamp() < $torrentData['date_uploaded_unix']) {
                    return true;
                }
            }
        }
        return false;
    }

    public function getTopic(TopicDto $topic)
    {
        [$movieId, ] = explode(':', $topic->id);
        $res = $this->client->get('/api/v2/movie_details.json', [
            'query' => [
                'movie_id' => $movieId,
            ]
        ]);
        $json = $res->getBody()->getContents();
        $data = json_decode($json, true);

        if (empty($data['data']['movie'])) {
            return ;
        }
        $movieData = $data['data']['movie'];
        $media = $this->torrentService->getMediaByImdb($movieData['imdb_code']);
        if (!($media instanceof Movie)) {
            return ;
        }

        foreach($movieData['torrents'] as $torrentData) {
            $this->buildTorrentFromData($media, $movieData, $torrentData);
        }
    }

    /**
     * @param Movie $media
     * @param array $movieData
     * @param array $torrentData
     */
    private function buildTorrentFromData(Movie $media, $movieData, $torrentData): void
    {
        $url = 'magnet:?xt=urn:btih:' . $torrentData['hash'] . '&' . implode('&', array_map(function ($item) {
            return 'tr=' . $item;
        }, [
            'udp://tracker.opentrackr.org:1337',
            'udp://tracker.tiny-vps.com:6969',
            'udp://tracker.openbittorrent.com:1337',
            'udp://tracker.coppersurfer.tk:6969',
            'udp://tracker.leechers-paradise.org:6969',
            'udp://p4p.arenabg.ch:1337',
            'udp://p4p.arenabg.com:1337',
            'udp://tracker.internetwarriors.net:1337',
            'udp://9.rarbg.to:2710',
            'udp://9.rarbg.me:2710',
            'udp://exodus.desync.com:6969',
            'udp://tracker.cyberia.is:6969',
            'udp://tracker.torrent.eu.org:451',
            'udp://open.stealth.si:80',
            'udp://tracker.moeking.me:6969',
            'udp://tracker.zerobytes.xyz:1337',
        ]));

        $newTorrent = new MovieTorrent();
        $newTorrent->setMovie($media);

        $torrent = $this->torrentService->findExistOrCreateTorrent(
            $this->getName(),
            $movieData['id'] . ':' . $torrentData['hash'],
            $newTorrent
        );
        $torrent
            ->setProviderTitle($movieData['title'])
            ->setUrl($url)
            ->setSeed($torrentData['seeds'])
            ->setPeer($torrentData['peers'])
            ->setQuality($torrentData['quality'])
            ->setLanguage($movieData['language']);

        $torrent->setSize($torrentData['size_bytes']);

        $this->torrentService->updateTorrent($torrent);
    }
}
