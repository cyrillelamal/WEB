<?php /** @noinspection PhpTemplateMissingInspection */


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

class ThemeController extends Controller
{
    const DB_NAME = 'web_portfolio';

    const COLLECTION_NAME = 'computerWorkshop';

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
     * List themes.
     *
     * @param Request $request
     * @return string
     * @throws LoaderError
     * @throws RuntimeError
     * @throws SyntaxError
     */
    public function index(Request $request)
    {
        $themes = $this->getCollection()->find();

        $apiToken = $request->cookie(SecurityController::COOKIE_NAME, null);

        $jsToken = null !== $apiToken && password_verify(env('APP_PASSWORD'), $apiToken)
                ? $jsToken = $apiToken
                : null
        ;

        return $this->twig->render('theme/index.html.twig', [
            'theme_list' => $themes,
            'js_token' => $jsToken,
            'user' => $request->user()
        ]);
    }

    /**
     * REST. Create a new theme.
     *
     * @param JsonResponse $response
     * @return string
     */
    public function create(JsonResponse $response)
    {
        // The form values.
        $name = request()->input('themeName');
        $slug = request()->input('themeSlug');

        $code = Response::HTTP_CREATED;
        $data = [
            'name' => $name,
            'slug' => $slug
        ];

        // The form validation.
        if ($name && $slug) {
            $slugify = new Slugify();

            $slug = $slugify->slugify($slug, '_');

            $collection = $this->getCollection();

            $theme = $collection->findOne(['slug' => $slug]);

            if (null === $theme) {
                $collection->insertOne(['name' => $name, 'slug' => $slug]);

                $data['msg'] = 'Successful';
                $data['themeDetailLink'] = route('theme_detail', ['themeSlug' => $slug]);
                $data['themeDeleteLink'] = route('theme_delete', ['themeSlug' => $slug]);
            } else {
                $code = Response::HTTP_BAD_REQUEST;
                $data['err'] = "Slug '$slug' is used already.";
            }
        } else {
            $data['err'] = 'Some fields are not provided';
        }

        return $response
            ->setStatusCode($code)
            ->setData($data)
        ;
    }

    /**
     * Display related articles.
     *
     * @param $themeSlug
     * @param Request $request
     * @param Response $response
     * @return string
     * @throws LoaderError
     * @throws RuntimeError
     * @throws SyntaxError
     */
    public function read($themeSlug, Request $request, Response $response)
    {
        $theme = $this->getCollection()->findOne(['slug' => $themeSlug]);

        $apiToken = $request->cookie(SecurityController::COOKIE_NAME, null);

        $jsToken = null;
        $jsApp = null;

        if (null !== $apiToken && password_verify(env('APP_PASSWORD'), $apiToken)) {
            // user is authorized
            // and tries to pass as authorized
            $jsToken = $apiToken;
            $jsApp = url('app.js');
        }
        if (null !== $theme) {
            $articles = $theme['articles'] ?? [];

            $response->setContent($this->twig->render('theme/detail.html.twig', [
                'theme' => $theme,
                'article_list' => $articles,
                'js_token' => $jsToken,
                'js_app' => $jsApp,
                'user' => $request->user()
            ]));
        } else {
            throw new NotFoundHttpException();
        }

        return $response;
    }

    /**
     * REST. Delete the theme and its articles.
     *
     * @param $themeSlug
     * @param JsonResponse $response
     * @return JsonResponse
     */
    public function delete($themeSlug, JsonResponse $response)
    {
        $delCount = $this->getCollection()->deleteOne(['slug' => $themeSlug])->getDeletedCount();

        $statusCode = Response::HTTP_ACCEPTED;
        $data = [
            'delCount' => $delCount
        ];

        if ($delCount) {
            $data['msg'] = 'Successful.';
        } else {
            $statusCode = Response::HTTP_BAD_REQUEST;
            $data['err'] = "No such slug '$themeSlug'";
        }

        return $response
            ->setStatusCode($statusCode)
            ->setData($data)
        ;
    }

    /**
     * Lazy loader of the related mongo collection.
     *
     * @return mixed
     */
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
