<?php
error_reporting( E_ALL );
ini_set( 'display_errors', 1 );

/*
 *
 * НАСТРОЙКИ СКРИПТА
 *
 * */
$langFrom = 'ru';                                       // ЯЗЫК ИСТОЧНИКА
$langTo = 'ru';                                         // ЯЗЫК ПЕРЕВОДА. ЕСЛИ ПЕРЕВОД НЕ НУЖЕН, УКАЗАТЬ ЯЗЫК ИСТОЧНИКА
$site = 'https://www.myjane.ru/articles/rubric/?id=3';  // ССЫЛКА НА РУБРИКУ СТАТЕЙ MYJANE.RU
$startPage = 1;                                         // НОМЕР СТРАНИЦЫ, С КОТОРОЙ НАЧИНАТЬ ПАРСИТЬ СТАТЬИ
$depth = 20;                                            // СКОЛЬКО СТРАНИЦ РУБРИКИ ПРОСМАТРИВАТЬ?
$articlesCount = 10;                                    // СКОЛЬКО СТАТЕЙ ДЕРГАТЬ ДЛЯ ВАЙТА?
$withImages = 1;                                        // ЗАГРУЖАТЬ ЛИ РАНДОМНЫЕ КАРТИНКИ С PIXABAY?
/*
 * ПРОКСИ
 * ФОРМАТ http://ip:port ИЛИ http://user:password@ip:port
 * ОСТАВИТЬ ПУСТЫМ, ЕСЛИ БЕЗ ПРОКСИ
 * */
$proxy = '';

/*
 *
 * ЗАГРУЗКА НЕОБХОДИМЫХ БИБЛИОТЕК
 *
 * */
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/zip.php';
require_once 'htmldom.php';

/*
 *
 * ИНИЦИАЛИЗАЦИЯ БИБЛИОТЕКИ ПЕРЕВОДА
 *
 * */

use Stichoza\GoogleTranslate\GoogleTranslate;

$tr = new GoogleTranslate();
$tr->setSource( $langFrom );
$tr->setTarget( $langTo );
$tr->setOptions( [ 'proxy' => $proxy ] );

/*
 *
 * СБОР ССЫЛОК НА СТАТЬИ C N ПЕРВЫХ СТРАНИЦ
 *
 * */
$articles = [];

if ( defined( 'STDIN' ) ) echo "Получаю ссылки на статьи\n";
for ( $page = $startPage; $page <= $startPage + $depth; $page++ ) {
    if ( defined( 'STDIN' ) ) echo "Просматриваю страницу #$page\n";
    $html = file_get_html( "$site&page=$page" );

    foreach ( $html->find( 'a' ) as $a ) {
        if ( strpos( $a->href, 'articles/text/?id=' ) !== false ) {
            if ( strpos( $a->href, 'comments' ) === false ) {
                $articles[] = $a->href;
            }
        }
    }
}

/*
 *
 * СОЗДАНИЕ ВАЙТА
 *
 * */

// удаление старых вайтов из папки output
$dir = __DIR__ . "/output/";
$allowedFiles = [ '.', '..', 'w.php', 'contact.php', 'themes' ];
$files = scandir( $dir );
foreach ( $files as $file ) {
    if ( !in_array( $file, $allowedFiles ) ) unlink( $dir . $file );
}

for ( $i = 1; $i <= $articlesCount; $i++ ) {
    /*
     *
     * ОБЩИЙ ПАРСИНГ СТАТЬИ
     *
     * */
    if ( defined( 'STDIN' ) ) echo "Обрабатываю статью #$i\n";
    $article = $articles[ array_rand( $articles ) ];
    unset( $articles[ $rand ] );
    $translated = [];

    $html = file_get_html( $article );
    $data[ 'title' ] = $tr->translate( $html->find( 'h1', 0 )->plaintext );
    $data[ 'text' ] = $html->find( 'div.usertext > div.usertext', 0 )->outertext;

    /*
     *
     * ОБРАБОТКА ТЕКСТА СТАТЬИ
     *
     * */
    $html = str_get_html( $data[ 'text' ] );

    // убрать все ссылки в статье
    foreach ( $html->find( 'a' ) as $a ) {
        $a->href = '#';
    }

    // убрать содержимое рекламных баннеров
    foreach ( $html->find( 'div > div' ) as $div ) {
        $div->innertext = '';
    }

    $html = $html->save();

    // поиск и запись краткого превью статьи
    preg_match( '/<b>.*<\/b>/', $html, $matches );
    if ( $matches ) {
        $data[ 'short' ] = str_replace( '<b>', '', str_replace( '</b>', '', $matches[ 0 ] ) ) . '...';
        $data[ 'short' ] = $tr->translate( $data[ 'short' ] );
    }

    // обработка мелочи в HTML-разметке
    $html = preg_replace( '/<a[^>]+>/', '', $html );
    $html = preg_replace( '/<\/a>/', '', $html );
    $html = preg_replace( '/<br \/> <br \/>/', '<br />', $html );
    $html = preg_replace( '/<br \/>/', '<p>', $html );
    $html = preg_replace( '/<br>/', '<p>', $html );
    $html = preg_replace( '/<b>/', '<p><b>', $html );
    $html = preg_replace( '/<\/b>/', '</b></p>', $html );
    $html = preg_replace( '/<div class\=\"usertext\">/', '', $html );
    $html = preg_replace( '/<div>/', '', $html );
    $html = preg_replace( '/<\/div>/', '', $html );
    $html = preg_replace( '/<h3><p>/', '<h3>', $html );

    // перевод статьи
    $parts = preg_split( '/(<[^>]*[^\/]>)/i', $html, -1, PREG_SPLIT_NO_EMPTY | PREG_SPLIT_DELIM_CAPTURE );
    foreach ( $parts as $p ) {
        if ( $langFrom != $langTo ) usleep( 500000 );
        if ( empty( trim( $p ) ) ) continue;
        if ( strpos( $p, '<' ) !== false ) {
            $translated[] = $p;
            continue;
        }
        $p = trim( $p );
        if ( mb_strlen( $p ) < 5000 ) {
            $translated[] = $tr->translate( $p );
        }
    }
    $html = implode( '', $translated );

    // записть готовой статьи в .html файл под ее порядковым номером
    $posts[] = [ 'id' => $i, 'title' => $data[ 'title' ], 'filename' => "$i.html", 'short' => $data[ 'short' ] ?? '' ];
    file_put_contents( "$dir/$i.html", $html );
    file_put_contents( "$dir/posts.json", json_encode( $posts ) );
}

/*
 *
 * ЗАГРУЗКА КАРТИНОК ДЛЯ СТАТЬЕЙ, ЕСЛИ НУЖНО
 *
 * */
if ( $withImages ) {
    foreach ( $posts as $post ) {
        $imageFile = "$dir/{$post['id']}.jpg";

        $image = getImage();
        echo "Загружаю изображение #{$post['id']}\n";
        file_put_contents( $imageFile, file_get_contents( $image ) );
    }
}

if ( defined( 'STDIN' ) ) echo "Выполняю прочую мелочевку\n";

/*
 *
 * МАССИВ ТЕКСТОВЫХ ЗНАЧЕНИЙ ЭЛЕМЕНТОВ ВАЙТА
 *
 * */
$lang = [
    'readMore'     => $tr->translate( 'Читать полностью...' ),
    'search'       => $tr->translate( 'Поиск' ),
    'searchInput'  => $tr->translate( 'Хочу найти...' ),
    'recentPosts'  => $tr->translate( 'Свежие статьи' ),
    'published'    => $tr->translate( 'Опубликовано' ),
    'prev'         => $tr->translate( 'Назад' ),
    'next'         => $tr->translate( 'Вперед' ),
    'blog'         => $tr->translate( 'Блог' ),
    'contact'      => $tr->translate( 'Контакты' ),
    'name'         => $tr->translate( 'Ваше имя' ),
    'message'      => $tr->translate( 'Сообщение' ),
    'send'         => $tr->translate( 'Отправить сообщение' ),
    'searchSubmit' => $tr->translate( 'Найти!' ),
    'images'       => $tr->translate( 'Галерея' ),
    'success'      => $tr->translate( 'Спасибо за ваше сообщение!' ),
];
file_put_contents( "$dir/lang.json", json_encode( $lang ) );

/*
 *
 * ЗАПИСЬ КОНФИГУРАЦИОННОГО ФАЙЛА
 *
 * */
$themes = [ 'Cerulean', 'Cosmo', 'Cyborg', 'Darkly', 'Flatly', 'Journal', 'Litera', 'Lumen', 'Lux', 'Materia', 'Minty', 'Pulse', 'Sandstone', 'Simplex', 'Slate', 'Solar', 'Spacelab', 'Superhero', 'United', 'Yeti' ];
$theme = strtolower( $themes[ rand( 0, count( $themes ) - 1 ) ] );
$config = [
    'theme' => $theme,
];
file_put_contents( "$dir/config.json", json_encode( $config ) );

/*
 *
 * СОЗДАНИЕ АРХИВА
 *
 * */
if ( defined( 'STDIN' ) ) echo "Создаю архив\n";
$zipFile = "$dir/white.zip";
if ( file_exists( $zipFile ) ) unlink( $zipFile );
zipData( $dir, $zipFile );

if ( defined( 'STDIN' ) ) {
    echo "Архив с вайтом готов! Он лежит в output/white.zip\n";
} else {
    header( "Pragma: public" );
    header( "Expires: 0" );
    header( "Cache-Control: must-revalidate, post-check=0, pre-check=0" );
    header( "Cache-Control: public" );
    header( "Content-Description: File Transfer" );
    header( "Content-type: application/octet-stream" );
    header( "Content-Disposition: attachment; filename=white.zip" );
    header( "Content-Transfer-Encoding: binary" );
    header( "Content-Length: " . filesize( $zipFile ) );
    ob_end_flush();
    @readfile( $zipFile );
}

function getImage () {
    usleep( 1000000 );
    $result = @file_get_contents( 'https://pixabay.com/api/?id=' . rand( 1, 999999 ) . '&key=7331766-71ba439a87eec21d8ee411b77' );
    if ( strpos( $result, 'ERROR 400' ) !== false || !$result ) {
        return getImage();
    } else {
        $result = json_decode( $result, true );

        return $result[ 'hits' ][ 0 ][ 'largeImageURL' ];
    }
}