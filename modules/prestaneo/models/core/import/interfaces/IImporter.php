<?php

/**
 * Interface IImporter
 */
interface IImporter
{
    /**
     * @return mixed
     * Template method to implement
     */
    public function import();

    /**
     * @return mixed
     */
    public function process();
}