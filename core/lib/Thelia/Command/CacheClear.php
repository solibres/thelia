<?php
/*************************************************************************************/
/*                                                                                   */
/*      Thelia	                                                                     */
/*                                                                                   */
/*      Copyright (c) OpenStudio                                                     */
/*      email : info@thelia.net                                                      */
/*      web : http://www.thelia.net                                                  */
/*                                                                                   */
/*      This program is free software; you can redistribute it and/or modify         */
/*      it under the terms of the GNU General Public License as published by         */
/*      the Free Software Foundation; either version 3 of the License                */
/*                                                                                   */
/*      This program is distributed in the hope that it will be useful,              */
/*      but WITHOUT ANY WARRANTY; without even the implied warranty of               */
/*      MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the                */
/*      GNU General Public License for more details.                                 */
/*                                                                                   */
/*      You should have received a copy of the GNU General Public License            */
/*	    along with this program. If not, see <http://www.gnu.org/licenses/>.         */
/*                                                                                   */
/*************************************************************************************/

namespace Thelia\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use Symfony\Component\Filesystem\Exception\IOException;

use Thelia\Core\Event\Cache\CacheEvent;
use Thelia\Core\Event\TheliaEvents;

/**
 * clear the cache
 *
 * Class CacheClear
 * @package Thelia\Command
 * @author Manuel Raynaud <mraynaud@openstudio.fr>
 *
 */
class CacheClear extends ContainerAwareCommand
{
    protected function configure()
    {
        $this
            ->setName("cache:clear")
            ->setDescription("Invalidate all caches")
            ->addOption(
                "without-assets",
                null,
                InputOption::VALUE_NONE,
                "do not clear the assets cache in the web space"
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {

        $cacheDir = $this->getContainer()->getParameter("kernel.cache_dir");

        $this->clearCache($cacheDir, $output);
        $this->clearCache(THELIA_WEB_DIR . "cache", $output);
        if (!$input->getOption("without-assets")) {
            $this->clearCache(THELIA_WEB_DIR . "assets", $output);
        }

    }

    protected function clearCache($dir, OutputInterface $output)
    {
        $output->writeln(sprintf("Clearing cache in <info>%s</info> directory", $dir));

        try {
            $cacheEvent = new CacheEvent($dir);
            $this->
                getContainer()
                ->get('event_dispatcher')
                ->dispatch(TheliaEvents::CACHE_CLEAR, $cacheEvent);
        } catch (\UnexpectedValueException $e) {
            // throws same exception code for does not exist and permission denied ...
            if (!file_exists($dir)) {
                $output->writeln(sprintf("<info>%s cache dir already cleared</info>", $dir));

                return;
            }

            throw $e;
        } catch (IOException $e) {
            $output->writeln(sprintf("Error during clearing of cache : %s", $e->getMessage()));
        }

        $output->writeln(sprintf("<info>%s cache directory cleared successfully</info>", $dir));

    }
}
