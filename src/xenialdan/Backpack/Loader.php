<?php

namespace xenialdan\Backpack;

use CortexPE\Commando\PacketHooker;
use muqsit\invmenu\InvMenu;
use muqsit\invmenu\InvMenuHandler;
use pocketmine\block\BlockFactory;
use pocketmine\block\BlockIds;
use pocketmine\entity\Entity;
use pocketmine\entity\Skin;
use pocketmine\item\Item;
use pocketmine\world\World;
use pocketmine\world\WorldException;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\StringTag;
use pocketmine\player\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\plugin\PluginException;
use pocketmine\block\tile\Tile;
use pocketmine\utils\TextFormat;

class Loader extends PluginBase
{
    const TAG_BACKPACK_OWNER = "owner";
    const TAG_BACKPACK = "backpack";
    const MENU_TYPE_BACKPACK_CHEST = "backpack:chest";
    /** @var self */
    private static $instance;
    /** @var Skin[] */
    public static $skins = [];
    /** @var array */
    public static $backpacks = [];

    /**
     * @param Player $player
     * @return string
     */
    public static function getSavePath(Player $player): string
    {
        return self::getInstance()->getDataFolder() . "players" . DIRECTORY_SEPARATOR . $player->getName() . ".nbt";
    }

    /**
     * @param Entity|null $player
     * @return bool
     */
    public static function wearsBackpack(?Entity $player): bool
    {
        return $player instanceof Player && $player->getGenericFlag(Entity::DATA_FLAG_CHESTED);
    }

    /**
     * @param Entity|null $player
     * @return bool
     */
    public static function wantsToWearBackpack(?Entity $player): bool
    {
        return $player instanceof Player && $player->isOnline() && self::getType($player) !== "none" && !self::wearsBackpack($player);
    }

    /**
     * @return self
     */
    public static function getInstance()
    {
        return self::$instance;
    }

    /**
     * @throws PluginException
     */
    public function onLoad(): void
    {
        if (!extension_loaded("gd")) {
            throw new PluginException("GD library is not enabled! Please uncomment gd2 in php.ini!");
        }
        self::$instance = $this;
        $this->saveDefaultConfig();
        $this->saveResource("default.png");
        $this->saveResource("default.json");
        @mkdir($this->getDataFolder() . "players");
        $defaultJson = file_get_contents($this->getDataFolder() . "default.json");
        foreach (glob($this->getDataFolder() . "*.png") as $imagePath) {
            $json = $defaultJson;
            $fileName = pathinfo($imagePath, PATHINFO_FILENAME);
            $jsonPath = pathinfo($imagePath, PATHINFO_DIRNAME) . DIRECTORY_SEPARATOR . $fileName . ".json";
            if (file_exists($jsonPath)) $json = file_get_contents($jsonPath);
            $skin = new Skin("backpack.$fileName", self::fromImage(imagecreatefrompng($imagePath)), "", "geometry.backpack.$fileName", $json);
            if (!$skin->isValid()) {
                $this->getLogger()->error("Resulting skin of $fileName is not valid");
                continue;
            }
            self::$skins[$fileName] = $skin;
        }

        $this->getLogger()->info($this->getDescription()->getPrefix() . TextFormat::GREEN . count(self::$skins) . " backpacks successfully loaded: " . implode(", ", array_keys(self::$skins)));

        //TODO test
        self::$skins["none"] = null;

        Backpack::init();

        Entity::registerEntity(BackpackEntity::class, true, ['backpack']);
        $this->getServer()->getCommandMap()->register("Backpack", new Commands($this, "backpack", "Manage your backpack"));
    }

    /**
     * from skinapi
     * @param resource $img
     * @return string
     */
    public static function fromImage($img): string
    {
        $bytes = '';
        for ($y = 0; $y < imagesy($img); $y++) {
            for ($x = 0; $x < imagesx($img); $x++) {
                $rgba = @imagecolorat($img, $x, $y);
                $a = ((~((int)($rgba >> 24))) << 1) & 0xff;
                $r = ($rgba >> 16) & 0xff;
                $g = ($rgba >> 8) & 0xff;
                $b = $rgba & 0xff;
                $bytes .= chr($r) . chr($g) . chr($b) . chr($a);
            }
        }
        @imagedestroy($img);
        return $bytes;
    }

    /**
     * @param Player $player
     */
    public static function loadBackpacks(Player $player): void
    {
        self::despawnBackpack($player);
        unset(self::$backpacks[$player->getName()]);
        $type = self::getType($player);
        if ($type === "none") return;
        if (file_exists(($path = self::getSavePath($player)))) {
            $backpack = new Backpack($player->getName(), $type);
            $backpack->read(file_get_contents($path));
            self::$backpacks[$player->getName()] = $backpack;
        }
    }

    /**
     * @param Player $player
     * @param string $type
     * @throws \InvalidArgumentException
     */
    public static function setType(Player $player, string $type = "none")
    {
        if (!array_key_exists($type, self::$skins)) throw new \InvalidArgumentException("Type $type does not exist");
        if ($type === "none") self::despawnBackpack($player);
        self::getInstance()->getConfig()->setNested("players." . $player->getName(), $type);
    }

    /**
     * @param Player $player
     * @return string
     */
    public static function getType(Player $player): string
    {
        return self::getInstance()->getConfig()->getNested("players." . $player->getName(), "none");
    }

    /**
     * @param Player $player
     */
    public static function saveBackpacks(Player $player): void
    {
        $backpack = self::getBackpack($player);
        if ($backpack === null) return;
        file_put_contents(self::getSavePath($player), $backpack->write());
        self::despawnBackpack($player);
    }

    /**
     * @param Player $player
     * @return Backpack|null
     */
    public static function getBackpack(Player $player): ?Backpack
    {
        return self::$backpacks[$player->getName()] ?? null;
    }

    /**
     * @param Player $player
     */
    public static function spawnBackpack(Player $player): void
    {
        if (self::wearsBackpack($player)) self::despawnBackpack($player);
        if (!self::wantsToWearBackpack($player)) return;
        $nbt = Entity::createBaseNBT($player->getPosition()->add(0, 1.85, 0), null, 2.5, 0);
        $nbt->setString(self::TAG_BACKPACK_OWNER, $player->getName());
        $nbt->setTag(new CompoundTag("Skin", [
            new StringTag("Name", self::$skins[self::getType($player)]->getSkinId()),
            new StringTag("Data", self::$skins[self::getType($player)]->getSkinData()),
            new StringTag("GeometryName", self::$skins[self::getType($player)]->getGeometryName()),
            new StringTag("GeometryData", self::$skins[self::getType($player)]->getGeometryData())
        ]));
        $player->getWorld()->loadChunk($player->getPosition()->getX() >> 4, $player->getPosition()->getZ() >> 4, true);
        $entity = new BackpackEntity($player->getWorld(), $nbt);
        $entity->setOwningEntity($player);
        $entity->spawnToAll();
        $entity->getDataPropertyManager()->setString(Entity::DATA_INTERACTIVE_TAG, "");
        $player->setGenericFlag(Entity::DATA_FLAG_CHESTED, true);
    }

    /**
     * @param Player $player
     */
    public static function despawnBackpack(Player $player): void
    {
        if (!self::wearsBackpack($player)) return;
        $entities = $player->getWorld()->getEntities();
        foreach ($entities as $entity) {
            if ($entity instanceof BackpackEntity && $entity->getOwningEntityId() === $player->getId()) {
                $entity->flagForDespawn();
            }
        }
        $player->setGenericFlag(Entity::DATA_FLAG_CHESTED, false);
    }

    /**
     * @throws WorldException
     */
    public function onEnable(): void
    {
        if (!PacketHooker::isRegistered()) PacketHooker::register($this);
        if (!InvMenuHandler::isRegistered()) InvMenuHandler::register($this);

        foreach ($this->getConfig()->getAll() as $playerName => $backpackType) {
            $player = $this->getServer()->getPlayerExact($playerName);
            if ($player !== null && $player->isOnline()) {
                self::setType($player, $backpackType);
                self::loadBackpacks($player);
            }
        }
        $this->getServer()->getPluginManager()->registerEvents(new EventListener(), $this);
    }

    /**
     *
     */
    public function onDisable(): void
    {
        foreach ($this->getServer()->getOnlinePlayers() as $player) {
            self::saveBackpacks($player);
        }
        $this->getConfig()->save();
    }
}
