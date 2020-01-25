<?php

declare(strict_types=1);

namespace DaPigGuy\PiggyAuctions\menu;

use DaPigGuy\PiggyAuctions\auction\Auction;
use DaPigGuy\PiggyAuctions\auction\AuctionBid;
use DaPigGuy\PiggyAuctions\PiggyAuctions;
use DaPigGuy\PiggyAuctions\tasks\InventoryClosureTask;
use DaPigGuy\PiggyAuctions\utils\Utils;
use jojoe77777\FormAPI\CustomForm;
use muqsit\invmenu\InvMenu;
use pocketmine\inventory\Inventory;
use pocketmine\inventory\transaction\action\SlotChangeAction;
use pocketmine\item\Item;
use pocketmine\nbt\tag\IntTag;
use pocketmine\nbt\tag\StringTag;
use pocketmine\Player;
use pocketmine\utils\TextFormat;

/**
 * Class Menu
 * @package DaPigGuy\PiggyAuctions\menu
 */
class Menu
{
    const PAGE_LENGTH = 45;

    /**
     * @param Player $player
     */
    public static function displayMainMenu(Player $player): void
    {
        $menu = InvMenu::create(InvMenu::TYPE_CHEST);
        $menu->setName(PiggyAuctions::getInstance()->getMessage("menus.main-menu.title"));
        $menu->getInventory()->setContents([
            11 => Item::get(Item::GOLD_BLOCK)->setCustomName(PiggyAuctions::getInstance()->getMessage("menus.main-menu.browse-auctions")),
            13 => Item::get(Item::GOLDEN_CARROT)->setCustomName(PiggyAuctions::getInstance()->getMessage("menus.main-menu.view-bids")),
            15 => Item::get(Item::GOLDEN_HORSE_ARMOR)->setCustomName(PiggyAuctions::getInstance()->getMessage("menus.main-menu.manage-auctions"))
        ]);
        $menu->setListener(function (Player $player, Item $itemClicked, Item $itemClickedWith, SlotChangeAction $action): bool {
            switch ($action->getSlot()) {
                case 11:
                    self::displayAuctionBrowser($player);
                    break;
                case 13:
                    self::displayBidsPage($player);
                    break;
                case 15:
                    if (count(PiggyAuctions::getInstance()->getAuctionManager()->getAuctionsHeldBy($player)) < 1) {
                        self::displayAuctionCreator($player);
                        break;
                    }
                    self::displayAuctionManager($player);
                    break;
            }
            return false;
        });
        $menu->send($player);
    }

    /**
     * @param Player $player
     * @param int $page
     */
    public static function displayAuctionBrowser(Player $player, int $page = 1): void
    {
        $menu = InvMenu::create(InvMenu::TYPE_DOUBLE_CHEST);
        $menu->setName(PiggyAuctions::getInstance()->getMessage("menus.auction-browser.title"));
        self::displayPageAuctions($menu->getInventory(), $page);

        PiggyAuctions::getInstance()->getScheduler()->scheduleRepeatingTask(new InventoryClosureTask($player, $menu->getInventory(), function () use ($menu, $page) : void {
            foreach ($menu->getInventory()->getContents() as $slot => $content) {
                if ($content->getNamedTagEntry("AuctionID") !== null) {
                    $auction = PiggyAuctions::getInstance()->getAuctionManager()->getAuction($content->getNamedTagEntry("AuctionID")->getValue());
                    if ($auction === null || $auction->hasExpired()) {
                        self::displayPageAuctions($menu->getInventory(), $page);
                        break;
                    }
                    $lore = $content->getNamedTagEntry("TemplateLore")->getValue();
                    $lore = str_replace("{DURATION}", Utils::formatDetailedDuration($auction->getEndDate() - time()), $lore);
                    $content->setLore(explode("\n", $lore));
                    $menu->getInventory()->setItem($slot, $content);
                }
            }
        }), 20);

        $menu->setListener(function (Player $player, Item $itemClicked, Item $itemClickedWith, SlotChangeAction $action): bool {
            if ($itemClicked->getNamedTagEntry("AuctionID") !== null) {
                $auction = PiggyAuctions::getInstance()->getAuctionManager()->getAuction($itemClicked->getNamedTagEntry("AuctionID")->getValue());
                $returnPage = $action->getInventory()->getItem(49)->getNamedTagEntry("CurrentPage")->getValue();
                self::displayItemPage($player, $auction, function (Player $player) use ($returnPage) {
                    self::displayAuctionBrowser($player, $returnPage);
                });
            }
            if ($itemClicked->getId() === Item::ARROW) {
                if ($itemClicked->getNamedTagEntry("Page") !== null) {
                    self::displayPageAuctions($action->getInventory(), $itemClicked->getNamedTagEntry("Page")->getValue());
                }
                if ($itemClicked->getNamedTagEntry("CurrentPage") !== null) {
                    self::displayMainMenu($player);
                }
            }
            return false;
        });
        $menu->send($player);
    }

    /**
     * @param Inventory $inventory
     * @param int $page
     * @return Auction[]
     */
    public static function displayPageAuctions(Inventory $inventory, int $page): array
    {
        $inventory->clearAll(false);
        $activeAuctions = PiggyAuctions::getInstance()->getAuctionManager()->getActiveAuctions();
        $displayedAuctions = self::updateDisplayedItems($inventory, $activeAuctions, ($page - 1) * self::PAGE_LENGTH, 0, self::PAGE_LENGTH);
        $backArrow = Item::get(Item::ARROW)->setCustomName(PiggyAuctions::getInstance()->getMessage("menus.back"));
        $backArrow->setNamedTagEntry(new IntTag("CurrentPage", $page));
        $inventory->setItem(49, $backArrow);
        if ($page > 1) {
            $previousPage = Item::get(Item::ARROW)->setCustomName(PiggyAuctions::getInstance()->getMessage("menus.auction-browser.previous-page", ["{PAGE}" => $page - 1, "{MAXPAGES}" => ceil(count($activeAuctions) / self::PAGE_LENGTH)]));
            $previousPage->setNamedTagEntry(new IntTag("Page", $page - 1));
            $inventory->setItem(45, $previousPage);
        }
        if ($page < ceil(count($activeAuctions) / self::PAGE_LENGTH)) {
            $nextPage = Item::get(Item::ARROW)->setCustomName(PiggyAuctions::getInstance()->getMessage("menus.auction-browser.next-page", ["{PAGE}" => $page + 1, "{MAXPAGES}" => ceil(count($activeAuctions) / self::PAGE_LENGTH)]));
            $nextPage->setNamedTagEntry(new IntTag("Page", $page + 1));
            $inventory->setItem(53, $nextPage);
        }
        return $displayedAuctions;
    }

    /**
     * @param Player $player
     */
    public static function displayBidsPage(Player $player): void
    {
        $menu = InvMenu::create(InvMenu::TYPE_CHEST);
        $menu->setName(PiggyAuctions::getInstance()->getMessage("menus.view-bids.title"));
        PiggyAuctions::getInstance()->getScheduler()->scheduleRepeatingTask(new InventoryClosureTask($player, $menu->getInventory(), function () use ($menu, $player): void {
            $auctions = array_filter(array_map(function (AuctionBid $bid): Auction {
                return $bid->getAuction();
            }, PiggyAuctions::getInstance()->getAuctionManager()->getBidsBy($player)), function (Auction $auction) use ($player): bool {
                return count($auction->getUnclaimedBidsHeldBy($player->getName())) > 0;
            });
            self::updateDisplayedItems($menu->getInventory(), $auctions, 0, 10, 7);
            $menu->getInventory()->setItem(22, Item::get(Item::ARROW)->setCustomName(PiggyAuctions::getInstance()->getMessage("menus.back")));
        }), 20);
        $menu->setListener(function (Player $player, Item $itemClicked, Item $itemClickedWith, SlotChangeAction $action): bool {
            switch ($action->getSlot()) {
                case 22:
                    self::displayMainMenu($player);
                    break;
                default:
                    self::displayItemPage($player, PiggyAuctions::getInstance()->getAuctionManager()->getAuction($itemClicked->getNamedTagEntry("AuctionID")->getValue()), function (Player $player) {
                        self::displayBidsPage($player);
                    });
                    break;
            }
            return false;
        });
        $menu->send($player);
    }

    /**
     * @param Player $player
     */
    public static function displayAuctionCreator(Player $player): void
    {
        $menu = InvMenu::create(InvMenu::TYPE_DOUBLE_CHEST);
        $menu->setName(PiggyAuctions::getInstance()->getMessage("menus.auction-creator.title"));
        for ($i = 0; $i < $menu->getInventory()->getSize(); $i++) $menu->getInventory()->setItem($i, Item::get(Item::BLEACH)->setCustomName(" "));
        $menu->getInventory()->setItem(13, Item::get(Item::AIR));
        $menu->getInventory()->setItem(29, Item::get(Item::STAINED_CLAY, 14)->setCustomName(PiggyAuctions::getInstance()->getMessage("menus.auction-creator.create-auction", ["{STATUS}" => TextFormat::RED])));
        $menu->getInventory()->setItem(31, Item::get(Item::GOLD_INGOT)->setCustomName(PiggyAuctions::getInstance()->getMessage("menus.auction-creator.starting-bid", ["{MONEY}" => 50])));
        $menu->getInventory()->setItem(33, Item::get(Item::CLOCK)->setCustomName(PiggyAuctions::getInstance()->getMessage("menus.auction-creator.duration", ["{DURATION}" => Utils::formatDuration(7200)])));
        $menu->getInventory()->setItem(49, Item::get(Item::ARROW)->setCustomName(PiggyAuctions::getInstance()->getMessage("menus.back")));
        $menu->setListener(function (Player $player, Item $itemClicked, Item $itemClickedWith, SlotChangeAction $action) use ($menu): bool {
            switch ($action->getSlot()) {
                case 13:
                    $action->getInventory()->setItem(13, $itemClickedWith);
                    $action->getInventory()->setItem(29, $action->getInventory()->getItem(29)->setDamage($itemClickedWith->getId() === Item::AIR ? 14 : 13)->setCustomName(PiggyAuctions::getInstance()->getMessage("menus.auction-creator.create-auction", ["{STATUS}" => $itemClickedWith->getId() === Item::AIR ? TextFormat::RED : TextFormat::GREEN])));
                    return true;
                case 29:
                    if ($itemClicked->getDamage() === 13) {
                        PiggyAuctions::getInstance()->getAuctionManager()->addAuction($player->getName(), $action->getInventory()->getItem(13), time(), time() + ($action->getInventory()->getItem(33)->getNamedTagEntry("Duration") ? $action->getInventory()->getItem(33)->getNamedTagEntry("Duration")->getValue() : 7200), $action->getInventory()->getItem(31)->getNamedTagEntry("StartingBid") ? $action->getInventory()->getItem(31)->getNamedTagEntry("StartingBid")->getValue() : 50);
                        $action->getInventory()->clear(13);
                        self::displayAuctionManager($player);
                    }
                    break;
                case 31:
                    $item = $action->getInventory()->getItem(13);
                    $action->getInventory()->clear(13);
                    $player->removeWindow($action->getInventory());
                    $form = new CustomForm(function (Player $player, ?array $data = null) use ($menu, $item): void {
                        if ($data !== null && is_numeric($data[0])) {
                            $menu->getInventory()->setItem(13, $item);

                            $item = $menu->getInventory()->getItem(31);
                            $item->setNamedTagEntry(new IntTag("StartingBid", (int)$data[0]));
                            $menu->getInventory()->setItem(31, $item->setCustomName(PiggyAuctions::getInstance()->getMessage("menus.auction-creator.starting-bid", ["{MONEY}" => (int)$data[0]])));
                        }
                        $menu->send($player);
                    });
                    $form->setTitle(PiggyAuctions::getInstance()->getMessage("forms.create-auction.title"));
                    $form->addInput(PiggyAuctions::getInstance()->getMessage("forms.create-auction.starting-bid"));
                    $player->sendForm($form);
                    break;
                case 33:
                    $item = $action->getInventory()->getItem(13);
                    $action->getInventory()->clear(13);
                    $player->removeWindow($action->getInventory());
                    $form = new CustomForm(function (Player $player, ?array $data = null) use ($menu, $item): void {
                        if ($data !== null && is_numeric($data[0])) {
                            $menu->getInventory()->setItem(13, $item);

                            $item = $menu->getInventory()->getItem(33);
                            $item->setNamedTagEntry(new IntTag("Duration", (int)$data[0]));
                            $menu->getInventory()->setItem(33, $item->setCustomName(PiggyAuctions::getInstance()->getMessage("menus.auction-creator.duration", ["{DURATION}" => (int)$data[0]])));
                        }
                        $menu->send($player);
                    });
                    $form->setTitle(PiggyAuctions::getInstance()->getMessage("forms.create-auction.title"));
                    $form->addInput(PiggyAuctions::getInstance()->getMessage("forms.create-auction.duration"));
                    $player->sendForm($form);
                    break;
                case 49:
                    if (count(PiggyAuctions::getInstance()->getAuctionManager()->getAuctionsHeldBy($player)) < 1) {
                        self::displayMainMenu($player);
                        break;
                    }
                    self::displayAuctionManager($player);
                    break;
            }
            return false;
        });
        $menu->send($player);
    }

    /**
     * @param Player $player
     */
    public static function displayAuctionManager(Player $player): void
    {
        $menu = InvMenu::create(InvMenu::TYPE_CHEST);
        $menu->setName(PiggyAuctions::getInstance()->getMessage("menus.auction-manager.title"));
        PiggyAuctions::getInstance()->getScheduler()->scheduleRepeatingTask(new InventoryClosureTask($player, $menu->getInventory(), function () use ($menu, $player): void {
            $auctions = array_filter(PiggyAuctions::getInstance()->getAuctionManager()->getAuctionsHeldBy($player), function (Auction $auction): bool {
                return !$auction->isClaimed();
            });
            self::updateDisplayedItems($menu->getInventory(), $auctions, 0, 10, 7);
        }), 20);
        $menu->getInventory()->setItem(22, Item::get(Item::ARROW)->setCustomName(PiggyAuctions::getInstance()->getMessage("menus.back")));
        $menu->getInventory()->setItem(24, Item::get(Item::GOLDEN_HORSE_ARMOR)->setCustomName(PiggyAuctions::getInstance()->getMessage("menus.auction-manager.create-auction")));
        $menu->setListener(function (Player $player, Item $itemClicked, Item $itemClickedWith, SlotChangeAction $action) use ($menu): bool {
            switch ($action->getSlot()) {
                case 22:
                    self::displayMainMenu($player);
                    break;
                case 24:
                    self::displayAuctionCreator($player);
                    break;
                default:
                    self::displayItemPage($player, PiggyAuctions::getInstance()->getAuctionManager()->getAuction($itemClicked->getNamedTagEntry("AuctionID")->getValue()), function (Player $player) {
                        self::displayAuctionManager($player);
                    });
                    break;
            }
            return false;
        });
        $menu->send($player);
    }

    /**
     * @param Player $player
     * @param string $auctioneer
     */
    public static function displayAuctioneerPage(Player $player, string $auctioneer): void
    {
        $menu = InvMenu::create(InvMenu::TYPE_CHEST);
        $menu->setName(PiggyAuctions::getInstance()->getMessage("menus.auctioneer-page.title", ["{PLAYER}" => $auctioneer]));
        PiggyAuctions::getInstance()->getScheduler()->scheduleRepeatingTask(new InventoryClosureTask($player, $menu->getInventory(), function () use ($menu, $auctioneer): void {
            $auctions = PiggyAuctions::getInstance()->getAuctionManager()->getActiveAuctionsHeldBy($auctioneer);
            if (isset(array_values($auctions)[0])) $menu->setName(PiggyAuctions::getInstance()->getMessage("menus.auctioneer-page.title", ["{PLAYER}" => array_values($auctions)[0]->getAuctioneer()]));
            self::updateDisplayedItems($menu->getInventory(), $auctions, 0, 10, 7);
        }), 20);
        $menu->setListener(function (Player $player, Item $itemClicked, Item $itemClickedWith, SlotChangeAction $action) use ($auctioneer): bool {
            self::displayItemPage($player, PiggyAuctions::getInstance()->getAuctionManager()->getAuction($itemClicked->getNamedTagEntry("AuctionID")->getValue()), function (Player $player) use ($auctioneer) {
                self::displayAuctioneerPage($player, $auctioneer);
            });
            return false;
        });
        $menu->send($player);
    }

    /**
     * @param Player $player
     * @param Auction $auction
     * @param callable $callback
     * @param int|null $bidAmount
     */
    public static function displayItemPage(Player $player, Auction $auction, callable $callback, ?int $bidAmount = null): void
    {
        $menu = InvMenu::create(InvMenu::TYPE_DOUBLE_CHEST);
        $menu->setName(PiggyAuctions::getInstance()->getMessage("menus.auction-view.title"));
        PiggyAuctions::getInstance()->getScheduler()->scheduleRepeatingTask(new InventoryClosureTask($player, $menu->getInventory(), function () use ($menu, $auction): void {
            $map = Item::get(Item::MAP);
            $menu->getInventory()->setItem(13, self::getDisplayItem($auction));
            $menu->getInventory()->setItem(33, $map->setCustomName(count($auction->getBids()) === 0 ? PiggyAuctions::getInstance()->getMessage("menus.auction-view.no-bids") : PiggyAuctions::getInstance()->getMessage("menus.auction-view.bid-history", ["{BIDS}" => count($auction->getBids()), "{HISTORY}" => implode("\n", array_map(function (AuctionBid $auctionBid): string {
                return PiggyAuctions::getInstance()->getMessage("menus.auction-view.bid-history-entry", ["{MONEY}" => $auctionBid->getBidAmount(), "{PLAYER}" => $auctionBid->getBidder(), "{DURATION}" => Utils::formatDuration(time() - $auctionBid->getTimestamp())]);
            }, array_reverse($auction->getBids())))])));
        }), 20);
        $bidAmount = $bidAmount ?? ($auction->getTopBid() === null ? $auction->getStartingBid() : $auction->getTopBid()->getBidAmount() * 1.15);
        $bidItem = Item::get(Item::POISONOUS_POTATO)->setCustomName(PiggyAuctions::getInstance()->getMessage("menus.auction-view.bidding.submit", ["{NEWBID}" => $bidAmount]));
        if ($auction->hasExpired()) {
            $bidItem = Item::get(Item::GOLD_NUGGET);
            if ($auction->getAuctioneer() === $player->getName()) {
                $bidItem->setCustomName(PiggyAuctions::getInstance()->getMessage("auction.claim.auctioneer-item"));
                if (count($auction->getBids()) > 0) $bidItem->setCustomName(PiggyAuctions::getInstance()->getMessage("auction.claim.auctioneer-money", ["{MONEY}" => $auction->getTopBid()->getBidAmount(), "{PLAYER}" => $auction->getTopBid()->getBidder()]));
            } elseif (($topBid = $auction->getTopBidBy($player->getName())) !== null) {
                $bidItem->setCustomName(PiggyAuctions::getInstance()->getMessage("auction.claim.bidder-money", ["{BID}" => $topBid->getBidAmount(), "{TOPBID}" => $auction->getTopBid()->getBidAmount(), "{TOPBIDDER}" => $auction->getTopBid()->getBidder()]));
                if ($topBid === $auction->getTopBid()) $bidItem->setCustomName(PiggyAuctions::getInstance()->getMessage("auction.claim.bidder-item", ["{MONEY}" => $topBid->getBidAmount()]));
            } else {
                $bidItem = Item::get(Item::POTATO);
                $bidItem->setCustomName(PiggyAuctions::getInstance()->getMessage("auction.claim.did-not-participate"));
            }
        } else if ($auction->getAuctioneer() === $player->getName()) {
            $bidItem->setCustomName(PiggyAuctions::getInstance()->getMessage("menus.auction-view.bidding.own-auction", ["{NEWBID}" => $bidAmount]));
        } else if (($topBid = $auction->getTopBidBy($player->getName())) === null) {
            if (PiggyAuctions::getInstance()->getEconomyProvider()->getMoney($player) < $bidAmount) {
                $bidItem->setCustomName(PiggyAuctions::getInstance()->getMessage("menus.auction-view.bidding.submit-first-cant-afford", ["{NEWBID}" => $bidAmount]));
            }
            $bidItem = Item::get(Item::GOLD_NUGGET);
            $bidItem->setCustomName(PiggyAuctions::getInstance()->getMessage("menus.auction-view.bidding.submit-first", ["{NEWBID}" => $bidAmount]));
        } else if (PiggyAuctions::getInstance()->getEconomyProvider()->getMoney($player) < ($bidAmount - $topBid->getBidAmount())) {
            $bidItem->setCustomName(PiggyAuctions::getInstance()->getMessage("menus.auction-view.bidding.submit-cant-afford", ["{NEWBID}" => $bidAmount, "{PREVIOUSBID}" => $topBid->getBidAmount(), "{DIFFERENCE}" => $bidAmount - $topBid->getBidAmount()]));
        } else if ($topBid === $auction->getTopBid()) {
            $bidItem = Item::get(Item::GOLD_BLOCK);
            $bidItem->setCustomName(PiggyAuctions::getInstance()->getMessage("menus.auction-view.bidding.top-bid", ["{NEWBID}" => $bidAmount, "{PREVIOUSBID}" => $topBid->getBidAmount()]));
        }
        $menu->getInventory()->setItem(29, $bidItem);
        if (PiggyAuctions::getInstance()->getEconomyProvider()->getMoney($player) >= $bidAmount) $menu->getInventory()->setItem(31, Item::get(Item::GOLD_INGOT)->setCustomName(PiggyAuctions::getInstance()->getMessage("menus.auction-view.bid-amount", ["{MONEY}" => $bidAmount])));
        $menu->getInventory()->setItem(49, Item::get(Item::ARROW)->setCustomName(PiggyAuctions::getInstance()->getMessage("menus.back")));
        $menu->setListener(function (Player $player, Item $itemClicked, Item $itemClickedWith, SlotChangeAction $action) use ($bidAmount, $auction, $callback): bool {
            switch ($action->getSlot()) {
                case 29:
                    if (!$auction->hasExpired()) {
                        if ($auction->getAuctioneer() === $player->getName()) {
                            $player->sendMessage(PiggyAuctions::getInstance()->getMessage("auction.bid.cant-self-bid"));
                            return false;
                        }
                        if ($auction->getTopBid() !== null && $auction->getTopBid()->getBidder() === $player->getName()) {
                            $player->sendMessage(PiggyAuctions::getInstance()->getMessage("auction.bid.already-top-bid"));
                            return false;
                        }
                        if (PiggyAuctions::getInstance()->getEconomyProvider()->getMoney($player) < $bidAmount) {
                            $player->sendMessage(PiggyAuctions::getInstance()->getMessage("auction.bid.cant-afford"));
                            return false;
                        }
                        $menu = InvMenu::create(InvMenu::TYPE_CHEST);
                        $menu->setName(PiggyAuctions::getInstance()->getMessage("menus.bid-confirmation.title"));
                        $menu->getInventory()->setItem(11, Item::get(Item::STAINED_CLAY, 13)->setCustomName(PiggyAuctions::getInstance()->getMessage("menus.bid-confirmation.confirm", ["{ITEM}" => $auction->getItem()->getName(), "{MONEY}" => $bidAmount])));
                        $menu->getInventory()->setItem(13, (clone $auction->getItem())->setCustomName(PiggyAuctions::getInstance()->getMessage("menus.bid-confirmation.bidding-on", ["{ITEM}" => ($auction->getItem())->getName()])));
                        $menu->getInventory()->setItem(15, Item::get(Item::STAINED_CLAY, 14)->setCustomName(PiggyAuctions::getInstance()->getMessage("menus.bid-confirmation.cancel")));
                        $menu->setListener(function (Player $player, Item $itemClicked, Item $itemClickedWith, SlotChangeAction $action) use ($bidAmount, $auction, $callback): bool {
                            switch ($action->getSlot()) {
                                case 11:
                                    if (($auction->getTopBid() === null && $bidAmount >= $auction->getStartingBid()) || $bidAmount >= (int)($auction->getTopBid()->getBidAmount() * 1.15)) {
                                        if ($auction->getTopBid() === null || $auction->getTopBid()->getBidder() !== $player->getName()) {
                                            if (PiggyAuctions::getInstance()->getEconomyProvider()->getMoney($player) >= $bidAmount) {
                                                PiggyAuctions::getInstance()->getEconomyProvider()->takeMoney($player, $bidAmount - ($auction->getTopBidBy($player->getName()) ?? 0));
                                                $auction->addBid(new AuctionBid($auction->getId(), $player->getName(), $bidAmount, time()));
                                            }
                                        }
                                    }
                                    self::displayItemPage($player, $auction, $callback, $bidAmount);
                                    break;
                                case 15:
                                    self::displayItemPage($player, $auction, $callback, $bidAmount);
                                    break;
                            }
                            return false;
                        });
                        $menu->send($player);
                    } else {
                        if ($auction->getAuctioneer() === $player->getName()) {
                            $auction->claim($player);
                        } else if ($auction->getTopBidBy($player->getName())) {
                            $auction->bidderClaim($player);
                        } else {
                            $player->sendMessage(PiggyAuctions::getInstance()->getMessage("auction.claim.didnt-participate-error"));
                        }
                    }
                    break;
                case 31:
                    if ($itemClicked->getId() === Item::GOLD_INGOT) {
                        $player->removeWindow($action->getInventory());
                        $form = new CustomForm(function (Player $player, ?array $data = null) use ($bidAmount, $callback, $auction): void {
                            if ($data !== null && is_numeric($data[0])) {
                                self::displayItemPage($player, $auction, $callback, (int)$data[0]);
                                return;
                            }
                            self::displayItemPage($player, $auction, $callback, $bidAmount);
                        });
                        $form->setTitle(PiggyAuctions::getInstance()->getMessage("forms.bid-amount.title"));
                        $form->addInput(PiggyAuctions::getInstance()->getMessage("forms.bid-amount.bid-amount"));
                        $player->sendForm($form);
                    }
                    break;
                case 49:
                    ($callback)($player);
                    break;
            }
            return false;
        });
        $menu->send($player);
    }

    /**
     * @param Inventory $inventory
     * @param Auction[] $auctions
     * @param int $arrayOffset
     * @param int $offsetSlot
     * @param int $displayCount
     * @param callable|null $sortFunction
     * @return Auction[]
     */
    public static function updateDisplayedItems(Inventory $inventory, array $auctions, int $arrayOffset, int $offsetSlot, int $displayCount, ?callable $sortFunction = null): array
    {
        if ($sortFunction === null) {
            $sortFunction = function (Auction $a, Auction $b): bool {
                return $a->getEndDate() > $b->getEndDate();
            };
        }
        uasort($auctions, $sortFunction);
        foreach (array_slice($auctions, $arrayOffset, $displayCount) as $index => $auction) {
            $inventory->setItem($offsetSlot + $index, self::getDisplayItem($auction));
        }
        return array_slice($auctions, $arrayOffset, $displayCount);
    }

    /**
     * @param Auction $auction
     * @return Item
     */
    public static function getDisplayItem(Auction $auction): Item
    {
        $item = clone $auction->getItem();

        $status = PiggyAuctions::getInstance()->getMessage("menus.auction-view.status-ongoing");
        if ($auction->hasExpired()) $status = PiggyAuctions::getInstance()->getMessage("menus.auction-view.status-ended");
        $lore = PiggyAuctions::getInstance()->getMessage("menus.auction-view.item-description-no-bid", ["{PLAYER}" => $auction->getAuctioneer(), "{BIDS}" => 0, "{STARTINGBID}" => $auction->getStartingBid(), "{STATUS}" => $status]);
        if ($auction->getTopBid() !== null) $lore = PiggyAuctions::getInstance()->getMessage("menus.auction-view.item-description", ["{PLAYER}" => $auction->getAuctioneer(), "{BIDS}" => count($auction->getBids()), "{TOPBID}" => $auction->getTopBid()->getBidAmount(), "{TOPBIDDER}" => $auction->getTopBid()->getBidder(), "{STATUS}" => $status]);
        $item->setNamedTagEntry(new StringTag("TemplateLore", $lore));
        $lore = str_replace("{DURATION}", Utils::formatDetailedDuration($auction->getEndDate() - time()), $lore);

        $item->setNamedTagEntry(new IntTag("AuctionID", $auction->getId()));
        return $item->setLore(explode("\n", $lore));
    }
}