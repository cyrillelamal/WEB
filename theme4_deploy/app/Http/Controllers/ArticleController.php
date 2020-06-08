<?php


namespace App\Http\Controllers;


use Cocur\Slugify\Slugify;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Lumen\Routing\Controller;
use MongoDB\Client;
use MongoDB\Collection;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Twig\Environment;
use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;
use Twig\Error\SyntaxError;

class ArticleController extends Controller
{
    const DB_NAME = ThemeController::DB_NAME;

    const COLLECTION_NAME = ThemeController::COLLECTION_NAME;

    /**
     * @var Client
     */
    private $mongo;

    /**
     * @var Environment
     */
    private $twig;

    /**
     * Related provider.
     *
     * @var Collection
     */
    private $collection = null;

    public function __construct(Client $mongo, Environment $twig)
    {
        $this->mongo = $mongo;
        $this->twig = $twig;
    }

    /**
     * Create article
     *
     * @param $themeSlug
     * @param Request $request
     * @param JsonResponse $response
     * @return JsonResponse|\Symfony\Component\HttpFoundation\JsonResponse
     */
    public function create($themeSlug, Request $request, JsonResponse $response)
    {
        // form data (+$themeSlug)
        $title = $request->input('title') ?? null;
        $body = $request->input('body') ?? null;
        $articleSlug = $request->input('slug') ?? null;

        $statusCode = Response::HTTP_BAD_REQUEST;
        $responseData = [
            'title' => $title,
            'body' => $body,
            'articleSlug' => $articleSlug,
            'themeSLug' => $themeSlug
        ];  // reflection

        $theme = $this->getCollection()->findOne(['slug' => $themeSlug]); // null

        if (null === $theme) {
            // The theme does not exist.
            // Article can't be bound.
            $responseData['err'] = "No such theme '$themeSlug'";
        } else {
            if ($title && $body && $articleSlug) {
                $articleSlug = (new Slugify())->slugify($articleSlug);
                $responseData['correctArticleSlug'] = $articleSlug;

                $articles = $theme['articles'] ?? [];
                $article = $articles[$articleSlug] ?? null;

                if (null === $article) {
                    // create new article (object)
                    $article = [
                        'title' => $title,
                        'body' => $body,
                        'slug' => $articleSlug
                    ];

                    $this->getCollection()->findOneAndUpdate(
                        ['slug' => $themeSlug],
                        ['$set' => ["articles.$articleSlug" => $article]]
                    );
                    $responseData['msg'] = 'Successful';
                    $statusCode = Response::HTTP_CREATED;
                } else {
                    // The unique slug constraints violation.
                    $responseData['err'] = "Article with slug '$articleSlug' exists already";
                }
            } else {
                // The form validation fails.
                $responseData['err'] = 'Some fields are not provided';
            }
        }

        return $response->setStatusCode($statusCode)->setData($responseData);
    }

    /**
     * Render article details.
     *
     * @param $themeSlug
     * @param $articleSlug
     * @return string
     * @throws LoaderError
     * @throws RuntimeError
     * @throws SyntaxError
     */
    public function read($themeSlug, $articleSlug, Request $request)
    {
        $theme = $this->getCollection()->findOne([
            'slug' => $themeSlug,
            "articles.$articleSlug" => ['$exists' => true]
        ]);

        if (null === $theme) {
            throw new NotFoundHttpException();
        }

        $articles = $theme['articles'] ?? [];
        $article = $articles[$articleSlug] ?? null;

        if (null === $article) {
            throw new NotFoundHttpException();
        }

        $user = \request()->user();

        $useWYSIWYG = \request()->user()
            ? true
            : false
        ;
        $this->twig->addGlobal('user', $user);

        $apiToken = $request->cookie(SecurityController::COOKIE_NAME, null);

        $jsToken = null !== $apiToken && password_verify(env('APP_PASSWORD'), $apiToken)
            ? $jsToken = $apiToken
            : null
        ;

        return $this->twig->render('article/detail.html.twig', [
            'article' => $article,
            'theme' => $theme,
            'use_WYSIWYG' => $useWYSIWYG,
            'js_token' => $jsToken,
            'old_title' => $article['title'],
            'old_body' => $article['body']
        ]);
    }

    /**
     * Update
     *
     * @param $themeSlug
     * @param $articleSlug
     * @param Request $request
     * @param JsonResponse $response
     * @return JsonResponse|\Symfony\Component\HttpFoundation\JsonResponse
     */
    public function update($themeSlug, $articleSlug, Request $request, JsonResponse $response)
    {
        $statusCode = Response::HTTP_BAD_REQUEST;
        $responseData = [];

        $newTitle = $request->input('title');
        $newBody = $request->input('body');

        if (null !== $newTitle && null !== $newBody) {
            $collection = $this->getCollection();

            $res = $collection->findOneAndUpdate(
                [
                    'slug' => $themeSlug,
                    "articles.$articleSlug" => ['$exists' => true]
                ],
                [
                    '$set' => [
                        "articles.$articleSlug.title" => $newTitle,
                        "articles.$articleSlug.body" => $newBody
                    ]
                ]
            );

            if (null === $res) {
                $responseData['err'] = "Cannot find such article '$themeSlug'.'$articleSlug'";
            } else {
                $statusCode = Response::HTTP_ACCEPTED;
                $responseData['msg'] = 'Successful';
            }
        } else {
            $responseData['err'] = 'Some fields are not provided';
        }

        return $response
            ->setStatusCode($statusCode)
            ->setData($responseData)
        ;
    }

    /**
     * REST. Delete the article object.
     *
     * @param $themeSlug
     * @param $articleSlug
     * @param JsonResponse $response
     * @return JsonResponse|\Symfony\Component\HttpFoundation\JsonResponse
     */
    public function delete($themeSlug, $articleSlug, JsonResponse $response)
    {
        $statusCode = Response::HTTP_OK;
        $responseData = [];

        $key = "articles.$articleSlug";

        $res = $this->getCollection()->findOneAndUpdate(
            [
                'slug' => $themeSlug, $key => ['$exists' => true]
            ],
            [
                '$unset' => [$key => '']
            ]
        );

        if (null === $res) {
            $statusCode = Response::HTTP_BAD_REQUEST;
            $responseData['err'] = "No such article slug '$articleSlug' or no theme with the slug '$themeSlug'";
        } else {
            $responseData['msg'] = 'Successful';
        }

        return $response
            ->setStatusCode($statusCode)
            ->setData($responseData)
        ;
    }

    private function getCollection(): Collection
    {
        if (null === $this->collection) {
            $this->collection = $this->mongo
                ->selectDatabase(self::DB_NAME)
                ->selectCollection(self::COLLECTION_NAME)
            ;
        }

        return $this->collection;
    }
}
