<?php

namespace Mdev\LaravelPrestashop\Client\Api\Request;

use Mdev\LaravelPrestashop\Client\Api\Client;
use Mdev\LaravelPrestashop\Client\Api\PrestashopApiException;
use Mdev\LaravelPrestashop\Client\Api\Response\Response;
use Mdev\LaravelPrestashop\Client\Api\Request\Contracts\Language as LanguageContract;

class Language extends Client implements LanguageContract
{

    /**
     * Get languages
     *
     * @param array $data
     * @return Response
     * @throws PrestashopApiException
     */
    public function getLanguages(array $options = []): Response
    {
        return new Response(
            $this->get(array_merge(['resource' =>'languages'], $options))
        );
    }

    /**
     * Get name language by ID
     *
     * @param int $languageId
     * @return string
     * @throws PrestashopApiException
     */
    public function getNameLanguageById($languageId){
        $response = $this->getLanguages(['display' =>'[name]', 'filter[id]' =>"[$languageId]"]);
        $languages = $response->getArray('//languages/language');
        if (!sizeof($languages)){
            throw new PrestashopApiException('Language not found in PrestaShop.', PrestashopApiException::ERROR_REQUEST_API_PRESTASHOP, 400, null, null, null, false);
        }
        return $languages[0]['name'];
    }


}
