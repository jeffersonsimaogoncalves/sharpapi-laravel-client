<?php

declare(strict_types=1);

namespace SharpAPI\SharpApiService;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use SharpAPI\SharpApiService\Dto\JobDescriptionParameters;
use SharpAPI\SharpApiService\Dto\SharpApiJob;
use SharpAPI\SharpApiService\Enums\SharpApiJobStatusEnum;

class SharpApiService
{
    protected string $apiBaseUrl;

    protected string $apiKey;

    protected int $apiJobStatusPollingInterval = 5;

    protected int $apiJobStatusPollingWait = 180;

    /**
     * Initializes a new instance of the class.
     *
     * @throws InvalidArgumentException if the API key is empty.
     */
    public function __construct()
    {
        $this->apiBaseUrl = config('sharpapi-client.base_url', 'https://sharpapi.com/api/v1');
        $this->apiKey = config('sharpapi-client.api_key');
        $this->apiJobStatusPollingInterval = (int) config('sharpapi-client.api_job_status_polling_interval', 5);
        $this->apiJobStatusPollingWait = (int) config('sharpapi-client.api_job_status_polling_wait', 180);

        if (empty($this->apiKey)) {
            throw new InvalidArgumentException('API key is required.');
        }
    }

    /**
     * Generic request method to run Guzzle client
     *
     * @throws GuzzleException
     */
    private function makeRequest(
        string $method,
        string $url,
        array $data = [],
        string $filePath = null
    ): ResponseInterface {
        $client = new Client();
        $options = [
            'headers' => $this->getHeaders(),
        ];
        if ($method === 'POST') {
            if (is_string($filePath) && strlen($filePath)) {
                $options['multipart'][] =
                    [
                        'name' => 'file',
                        'contents' => file_get_contents($filePath),
                        'filename' => basename($filePath),
                    ];
            } else {
                $options['json'] = $data;
            }
        }

        return $client->request($method, $url, $options);
    }

    private function parseStatusUrl(ResponseInterface $response)
    {
        return json_decode($response->getBody()->__toString(), true)['status_url'];
    }

    /**
     * Generic method to check job status in polling mode and then fetch results of the dispatched job
     *
     * @throws ClientException|GuzzleException
     *
     * @api
     */
    public function pollJobStatusAndFetchResults(string $statusUrl): SharpApiJob
    {
        $client = new Client();
        $waitingTime = 0;

        do {
            $response = $client->request(
                'GET',
                $statusUrl,
                ['headers' => $this->getHeaders()]
            );
            $jobStatus = json_decode($response->getBody()->__toString(), true)['data']['attributes'];

            if (
                $jobStatus['status'] === SharpApiJobStatusEnum::SUCCESS->value
                ||
                $jobStatus['status'] === SharpApiJobStatusEnum::FAILED->value
            ) {
                break;
            }   // it's still `pending` status, let's wait a bit more
            $retryAfter = isset($response->getHeader('Retry-After')[0])
                ? (int) $response->getHeader('Retry-After')[0]
                : (int) config('sharpapi-client.api_job_status_polling_interval'); // fallback if no Retry-After header

            if (config('sharpapi-client.api_job_status_use_polling_interval')) {
                // let's force to use the value from config
                $retryAfter = (int) config('sharpapi-client.api_job_status_polling_interval');
            }
            $waitingTime = $waitingTime + $retryAfter;
            if ($waitingTime >= config('sharpapi-client.api_job_status_polling_wait')) {
                break;
            } // otherwise wait a bit more and try again
            sleep($retryAfter);
        } while (true);
        $data = json_decode($response->getBody()->__toString(), true)['data'];

        return new SharpApiJob(
            id: $data['id'],
            type: $data['attributes']['type'],
            status: $data['attributes']['status'],
            result: $data['attributes']['result'] ?? null
        );
    }

    /**
     * Prepare shared headers
     *
     * @return string[]
     */
    private function getHeaders(): array
    {
        return [
            'Authorization' => 'Bearer '.$this->apiKey,
            'Accept' => 'application/json',
            'User-Agent' => 'SharpAPILaravelAgent/1.0.0',
        ];
    }

    /**
     * Parses a resume (CV) file from multiple formats (PDF/DOC/DOCX/TXT/RTF)
     * and returns an extensive JSON object of data points.
     *
     * An optional language parameter can also be provided (`English` value is set as the default one) .
     *
     * @param  string  $filePath The path to the resume file.
     * @param  string  $language The language of the resume file. Defaults to 'English'.
     * @return string The parsed data or an error message.
     *
     * @throws RequestException If there is an issue with the API request.
     * @throws GuzzleException
     *
     * @api
     */
    public function parseResume(string $filePath, string $language = 'English'): string
    {
        $url = $this->apiBaseUrl.'/hr/parse_resume';
        $response = $this->makeRequest(
            'POST',
            $url,
            ['language' => $language],
            $filePath
        );

        return $this->parseStatusUrl($response);
    }

    /**
     * Generates a job description based on a set of parameters
     * provided via JobDescriptionParameters DTO object.
     * This endpoint provides concise job details in the response format,
     * including the short description, job requirements, and job responsibilities.
     *
     * Only the job position `name` parameter is required inside $jobDescriptionParameters
     *
     * @throws GuzzleException
     *
     * @api
     */
    public function generateJobDescription(JobDescriptionParameters $jobDescriptionParameters): string
    {
        $url = $this->apiBaseUrl.'/hr/job_description';
        $response = $this->makeRequest('POST', $url, $jobDescriptionParameters->toArray());

        return $this->parseStatusUrl($response);
    }

    /**
     * Generates a list of related skills with their weights as a float value (1.0-10.0)
     * where 10 equals 100%, the highest relevance score.
     *
     * @throws GuzzleException
     *
     * @api
     */
    public function relatedSkills(string $skillName, string $language = 'English'): string
    {
        $url = $this->apiBaseUrl.'/hr/related_skills';
        $response = $this->makeRequest('POST', $url, [
            'content' => $skillName,
            'language' => $language,
        ]);

        return $this->parseStatusUrl($response);
    }

    /**
     * Generates a list of related job positions with their weights as float value (1.0-10.0)
     * where 10 equals 100%, the highest relevance score.
     *
     * @throws GuzzleException
     *
     * @api
     */
    public function relatedJobPositions(string $jobPositionName, string $language = 'English'): string
    {
        $url = $this->apiBaseUrl.'/hr/related_job_positions';
        $response = $this->makeRequest('POST', $url, [
            'content' => $jobPositionName,
            'language' => $language,
        ]);

        return $this->parseStatusUrl($response);
    }

    /**
     * Parses the customer's product review and provides its sentiment (POSITIVE/NEGATIVE/NEUTRAL)
     * with a score between 0-100%. Great for sentiment report processing for any online store.
     *
     * @throws GuzzleException
     *
     * @api
     */
    public function productReviewSentiment(string $review): string
    {
        $url = $this->apiBaseUrl.'/ecommerce/review_sentiment';
        $response = $this->makeRequest('POST', $url, [
            'content' => $review,
        ]);

        return $this->parseStatusUrl($response);
    }

    /**
     * Generates a list of suitable categories for the product with relevance weights as a float value (1.0-10.0)
     * where 10 equals 100%, the highest relevance score. Provide the product name and its parameters
     * to get the best category matches possible. Comes in handy with populating
     * product catalogue data and bulk products' processing.
     *
     * @throws GuzzleException
     *
     * @api
     */
    public function productCategories(string $productName, string $language = 'English'): string
    {
        $url = $this->apiBaseUrl.'/ecommerce/product_categories';
        $response = $this->makeRequest('POST', $url, [
            'content' => $productName,
            'language' => $language,
        ]);

        return $this->parseStatusUrl($response);
    }

    /**
     * Generates a shorter version of the product description.
     * Provide as many details and parameters of the product to get the best marketing introduction possible.
     * Comes in handy with populating product catalog data and bulk products processing.
     *
     * @throws GuzzleException
     *
     * @api
     */
    public function generateProductIntro(string $productData, string $language = 'English'): string
    {
        $url = $this->apiBaseUrl.'/ecommerce/product_intro';
        $response = $this->makeRequest('POST', $url, [
            'content' => $productData,
            'language' => $language,
        ]);

        return $this->parseStatusUrl($response);
    }

    /**
     * Generates a personalized thank-you email to the customer after the purchase.
     * The response content does not contain the title, greeting or sender info at the end,
     * so you can personalize the rest of the email easily.
     *
     * @throws GuzzleException
     *
     * @api
     */
    public function generateThankYouEmail(string $productName, string $language = 'English'): string
    {
        $url = $this->apiBaseUrl.'/ecommerce/thank_you_email';
        $response = $this->makeRequest('POST', $url, [
            'content' => $productName,
            'language' => $language,
        ]);

        return $this->parseStatusUrl($response);
    }

    /**
     * Parses the provided text for any phone numbers and returns the original detected version and its E.164 format.
     * Might come in handy in the case of processing and validating big chunks of data against phone numbers
     * or f.e. if you want to detect phone numbers in places where they're not supposed to be.
     *
     * @throws GuzzleException
     *
     * @api
     */
    public function detectPhones(string $text): string
    {
        $url = $this->apiBaseUrl.'/content/detect_phones';
        $response = $this->makeRequest('POST', $url, [
            'content' => $text,
        ]);

        return $this->parseStatusUrl($response);
    }

    /**
     * Parses the provided text for any possible emails. Might come in handy in case of processing and validating
     * big chunks of data against email addresses or f.e. if you want to detect emails in places
     * where they're not supposed to be.
     *
     * @throws GuzzleException
     *
     * @api
     */
    public function detectEmails(string $text): string
    {
        $url = $this->apiBaseUrl.'/content/detect_emails';
        $response = $this->makeRequest('POST', $url, [
            'content' => $text,
        ]);

        return $this->parseStatusUrl($response);
    }

    /**
     * Parses the provided text for any possible spam content.
     * It returns
     *
     * @throws GuzzleException
     *
     * @api
     */
    public function detectSpam(string $text): string
    {
        $url = $this->apiBaseUrl.'/content/detect_spam';
        $response = $this->makeRequest('POST', $url, [
            'content' => $text,
        ]);

        return $this->parseStatusUrl($response);
    }

    /**
     * Generates a summarized version of the provided content.
     * Perfect for generating marketing introductions of longer texts.
     *
     * @throws GuzzleException
     *
     * @api
     */
    public function summarizeText(string $text, string $language = 'English'): string
    {
        $url = $this->apiBaseUrl.'/content/summarize';
        $response = $this->makeRequest('POST', $url, [
            'content' => $text,
            'language' => $language,
        ]);

        return $this->parseStatusUrl($response);
    }

    /**
     * Translates the provided text into selected language
     * Perfect for generating marketing introductions of longer texts.
     *
     * @throws GuzzleException
     *
     * @api
     */
    public function translate(string $text, string $language): string
    {
        $url = $this->apiBaseUrl.'/content/translate';
        $response = $this->makeRequest('POST', $url, [
            'content' => $text,
            'language' => $language,
        ]);

        return $this->parseStatusUrl($response);
    }

    /**
     * Generates all most important META tags based on the content provided.
     * Make sure to include link to the website and pictures URL to get as many tags populated as possible.
     *
     * @throws GuzzleException
     *
     * @api
     */
    public function generateSeoTags(string $text, string $language = 'English'): string
    {
        $url = $this->apiBaseUrl.'/seo/generate_tags';
        $response = $this->makeRequest('POST', $url, [
            'content' => $text,
            'language' => $language,
        ]);

        return $this->parseStatusUrl($response);
    }

    /**
     * Parses the Travel/Hospitality product review and provides its sentiment (POSITIVE/NEGATIVE/NEUTRAL)
     * with a score between 0-100%. Great for sentiment report processing for any online store.
     *
     * @throws GuzzleException
     *
     * @api
     */
    public function travelReviewSentiment(string $text): string
    {
        $url = $this->apiBaseUrl.'/tth/review_sentiment';
        $response = $this->makeRequest('POST', $url, [
            'content' => $text,
        ]);

        return $this->parseStatusUrl($response);
    }

    /**
     * Generates a list of suitable categories for the Tours & Activities product
     * with relevance weights as float value (1.0-10.0) where 10 equals 100%, the highest relevance score.
     * Provide the product name and its parameters to get the best category matches possible.
     * Comes in handy with populating product catalogue data and bulk product processing.
     *
     * @throws GuzzleException
     *
     * @api
     */
    public function toursAndActivitiesProductCategories(
        string $productName,
        string $city = '',
        string $country = '',
        string $language = 'English'
    ): string {
        $url = $this->apiBaseUrl.'/tth/ta_product_categories';
        $response = $this->makeRequest('POST', $url, [
            'content' => $productName,
            'city' => $city,
            'country' => $country,
            'language' => $language,
        ]);

        return $this->parseStatusUrl($response);
    }

    /**
     * Generates a list of suitable categories for the Hospitality type product
     * with relevance weights as float value (1.0-10.0) where 10 equals 100%, the highest relevance score.
     * Provide the product name and its parameters to get the best category matches possible.
     * Comes in handy with populating products catalogs data and bulk products' processing.
     *
     * @throws GuzzleException
     *
     * @api
     */
    public function hospitalityProductCategories(
        string $productName,
        string $city = '',
        string $country = '',
        string $language = 'English'
    ): string {
        $url = $this->apiBaseUrl.'/tth/hospitality_product_categories';
        $response = $this->makeRequest('POST', $url, [
            'content' => $productName,
            'city' => $city,
            'country' => $country,
            'language' => $language,
        ]);

        return $this->parseStatusUrl($response);
    }
}
