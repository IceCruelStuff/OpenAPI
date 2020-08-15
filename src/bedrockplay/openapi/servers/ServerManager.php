<?php

declare(strict_types=1);

namespace bedrockplay\openapi\servers;

use bedrockplay\openapi\mysql\DatabaseData;
use bedrockplay\openapi\mysql\query\FetchTableQuery;
use bedrockplay\openapi\mysql\query\LazyRegisterServerQuery;
use bedrockplay\openapi\mysql\query\UpdateRowQuery;
use bedrockplay\openapi\mysql\QueryQueue;
use bedrockplay\openapi\OpenAPI;
use mysqli;
use pocketmine\scheduler\ClosureTask;

/**
 * Class ServerManager
 * @package bedrockplay\openapi
 */
class ServerManager {

    protected const REFRESH_TICKS = 40;

    /** @var Server[] $servers */
    private static $servers = [];
    /** @var ServerGroup[] $serverGroups */
    private static $serverGroups = [];

    /** @var Server $currentServer */
    private static $currentServer;

    public static function init() {
        /** @var string $currentServerName */
        $currentServerName = OpenAPI::getInstance()->getConfig()->get("current-server-name");
        self::updateServerData($currentServerName, "null", $currentServerPort = \pocketmine\Server::getInstance()->getConfigInt("server-port"));
        QueryQueue::submitQuery(new LazyRegisterServerQuery($currentServerName, $currentServerPort));

        self::$currentServer = self::getServer($currentServerName);

        OpenAPI::getInstance()->getScheduler()->scheduleRepeatingTask(new ClosureTask(function (int $currentTick): void {
            QueryQueue::submitQuery(new FetchTableQuery("Servers"), function (FetchTableQuery $query) {
                foreach ($query->rows as $row) {
                    if(ServerManager::getCurrentServer()->getServerName() === $row["ServerName"]) {
                        continue;
                    }

                    ServerManager::updateServerData(
                        $row["ServerName"],
                        $row["ServerAlias"],
                        (int)$row["ServerPort"],
                        (int)$row["OnlinePlayers"],
                        $row["IsOnline"] == "1",
                        $row["IsWhitelisted"] == "1"
                    );
                }
            });
        }), self::REFRESH_TICKS);
    }

    public static function save() {
        $query = new UpdateRowQuery(["IsOnline" => 0], "ServerName", self::getCurrentServer()->getServerName(), "Servers");
        $query->query(new mysqli(DatabaseData::getHost(), DatabaseData::getUser(), DatabaseData::getPassword(), DatabaseData::DATABASE));
    }

    /**
     * @param string $serverName
     * @param string $serverAlias
     * @param int $serverPort
     * @param int $onlinePlayers
     * @param bool $isOnline
     * @param bool $isWhitelisted
     */
    public static function updateServerData(string $serverName, string $serverAlias, int $serverPort, int $onlinePlayers = 0, bool $isOnline = false, bool $isWhitelisted = false) {
        if(!isset(self::$servers[$serverName])) {
            self::$servers[$serverName] = $server = new Server($serverName, $serverAlias, $serverPort, $onlinePlayers, $isOnline, $isWhitelisted);
            OpenAPI::getInstance()->getLogger()->info("§aRegistered new server ($serverName)");

            $isAdded = false;
            foreach (self::$serverGroups as $group) {;
                if($isAdded = $group->canAddServer($server)) {
                    $group->addServer($server);
                    break;
                }
            }

            if(!$isAdded) {
                self::$serverGroups[] = $group = new ServerGroup(substr($serverName, 0, strpos($serverName , "-")));
                $group->addServer($server);
            }
            return;
        }

        self::$servers[$serverName]->update($serverName, $serverAlias, $serverPort, $onlinePlayers, $isOnline, $isWhitelisted);
    }

    /**
     * @param string $name
     * @return Server|null
     */
    public static function getServer(string $name): ?Server {
        return self::$servers[$name] ?? null;
    }

    /**
     * @return ServerGroup[]
     */
    public static function getServerGroups(): array {
        return self::$serverGroups;
    }

    /**
     * @return Server
     */
    public static function getCurrentServer(): Server {
        return self::$currentServer;
    }
}