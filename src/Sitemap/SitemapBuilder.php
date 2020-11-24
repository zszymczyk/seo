<?php
namespace Melbahja\Seo\Sitemap;

use SimpleXMLElement;
use Melbahja\Seo\{
	Exceptions\SitemapException,
	Interfaces\SitemapBuilderInterface
};

/**
 * @package Melbahja\Seo
 * @since v1.0
 * @see https://git.io/phpseo 
 * @license MIT
 * @copyright 2019 Mohamed Elabhja 
 */
class SitemapBuilder implements SitemapBuilderInterface
{

	/**
	 * validations
	 * @var array
	 */
	protected $validation = 
	[
		'video' => ['thumbnail_loc', 'title', 'description'],
		'freq' => ['always', 'hourly', 'daily', 'weekly', 'monthly', 'yearly', 'never']
	]

	/**
	 * Url tag
	 * @var array
	 */
	, $url = []

	/**
	 * Sitemap domain name
	 * @var string
	 */
	, $domain

	/**
	 * Sitemap name
	 * @var string
	 */
	, $name

	/**
	 * Maximum urls in single sitemap (The maximum by google is 50000 urls but not 50MB size)
	 * @var integer
	 */
	, $max = 30000

	/**
	 * @var SimpleXMLElement
	 */
	, $doc

	/**
	 * Sitemap options
	 */
	, $options = 
	[
		'images' => false,
		'videos' => false,
		'escape' => true
	];


	/**
	 * Initialize sitemap builder
	 *
	 * @param string     $domain
	 * @param array|null $options
	 * @param string     $ns
	 */
	public function __construct(string $domain, ?array $options = null, string $ns = '')
	{
		$this->domain = $domain;
		$this->options = array_merge($this->options, $options ?? []);
		$urlset = '<!-- Generated at: '.date('Y-m-d H:i:s').' --><?xml-stylesheet type="text/xsl" href="https://dev.rejsy4you.pl/tools/sitemap.xsl"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" ';

		if ($this->options['images']) {
			$urlset .= ' xmlns:image="'. static::IMAGE_NS .'"';
		}

		if ($this->options['videos']) {

			$urlset .= ' xmlns:video="'. static::VIDEO_NS .'"';	
		}

		$this->doc = new SimpleXMLElement('<?xml version="1.0" encoding="UTF-8" ?>' . $urlset . "{$ns}/>");
	}

	/**
	 * Append last url and start new one
	 *
	 * @param  string $path
	 * @return SitemapBuilderInterface      
	 */
	public function loc(string $path): SitemapBuilderInterface
	{
		if ($path[0] !== '/') {

			$path = "/{$path}";
		}

		return $this->append()->url($this->domain . $path);
	}

	/**
	 * Register new url
	 *
	 * @param  string $url
	 * @return SitemapBuilderInterface
	 */
	public function url(string $url): SitemapBuilderInterface
	{
		if ($this->max <= 0) {

			throw new SitemapException("The maximum urls has been exhausted");
		}

		$this->url['loc'] = $this->escapeUrl($url);
		return $this;
	}

	/**
	 * Append url
	 *
	 * @return SitemapBuilderInterface
	 */
	public function append(): SitemapBuilderInterface
	{
		if (empty($this->url) === false) {

			$url = $this->doc->addChild('url');

			foreach ($this->url as $n => $v)
			{
				if ($n === 'image' || $n === 'video') {

					foreach ($v as $options)
					{
						$child = $url->addChild(
							"{$n}:{$n}", null, ($n === 'image' ? static::IMAGE_NS : static::VIDEO_NS)
						);

						foreach ($options as $x => $o)
						{
							$child->addChild("{$n}:{$x}", $o);
						}
					}

					continue;
				
				} elseif ($n === 'news') {

					$child = $url->addChild('news:news', null, static::NEWS_NS);

					$pub = $child->addChild('news:publication');
					$pub->addChild('news:name', $v['name']);
					$pub->addChild('news:language', $v['language']);
					unset($v['name'], $v['language']);

					foreach ($v as $k => $p)
					{
						$child->addChild("{$n}:{$k}", $p);
					}
					
					continue;
				}

				$url->addChild($n, $v);
			}

			$this->max--;
			$this->url = [];
		}

		return $this;
	}

	/**
	 * Last modification date
	 *
	 * @param  int|string $date Timestamps or date
	 * @return SitemapBuilderInterface
	 */
	public function lastMode($date): SitemapBuilderInterface
	{
		$this->url['lastmod'] = $this->pasreDate($date);

		return $this;
	}

	/**
	 * I Don't know where my mind was when i named lastMode!
	 *
	 * @return SitemapBuilderInterface
	 */
	public function lastMod($date): SitemapBuilderInterface
	{
		return $this->lastMode($date);
	}

	/**
	 * Set image
	 *
	 * @todo  Validate image options 
	 * @param  string  $imageUrl
	 * @param  array  $options
	 * @return SitemapBuilderInterface
	 */
	public function image(string $imageUrl, array $options = []): SitemapBuilderInterface
	{
		if ($this->options['images'] === false) {

			throw new SitemapException("Before set a image, enable images option");
		}

		$options['loc'] = $this->getByRelativeUrl($imageUrl);
		$this->url['image'][] = $options;

		return $this;
	}

	/**
	 * Set a video
	 *
	 * @param  string $title
	 * @param  array  $options
	 * @return SitemapBuilderInterface
	 */
	public function video(string $title, array $options = []): SitemapBuilderInterface
	{
		if ($this->options['videos'] === false) {

			throw new SitemapException("Before set a video, enable videos option first");
		}

		$options['title'] = $title;

		if (isset($options['thumbnail'])) {

			$options['thumbnail_loc'] = $options['thumbnail'];
			unset($options['thumbnail']);
		}

		foreach ($this->validation['video'] as $v)
		{
			if (isset($options[$v]) === false) {

				throw new SitemapException("video {$v} options is required");
			}
		}

		if (isset($options['content_loc']) === false && isset($options['player_loc']) === false) {

			throw new SitemapException("Raw video url content_loc or player_loc is required");
		}

		$this->url['video'][] = $options;

		return $this;
	}

	/**
	 * @param  string $freq
	 * @return SitemapBuilderInterface
	 */
	public function changefreq(string $freq): SitemapBuilderInterface
	{
		if (in_array($freq, $this->validation['freq']) === false) {

			throw new SitemapException("changefreq value not valid");
		}

		$this->url['changefreq'] = $freq;

		return $this;
	}

	/**
	 * changefreq alias
	 *
	 * @param  string $freq
	 * @return SitemapBuilderInterface
	 */
	public function freq(string $freq): SitemapBuilderInterface
	{
		return $this->changefreq($freq);
	}

	/**
	 * Url priority
	 *
	 * @param  string  $priority
	 * @return SitemapBuilderInterface
	 */
	public function priority(string $priority): SitemapBuilderInterface
	{
		$this->url['priority'] = $priority;
		return $this;
	}

	/**
	 * Get domain name
	 *
	 * @return string
	 */
	public function getDomain(): string
	{
		return $this->domain;
	}

	/**
	 * Save generated sitemap as file
	 *
	 * @param  string $path
	 * @return bool
	 */
	public function saveTo(string $path): bool
	{
		return $this->append()->getDoc()->asXML($path);
	}

	/**
	 * Save to temp
	 *
	 * @return string
	 */
	public function saveTemp(): string
	{
		if ($this->saveTo($temp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . md5(uniqid()))) {

			return $temp;
		}

		throw new SitemapException("Saving {$this->name} to temp failed");	
	}

	/**
	 * Get XML object
	 * 
	 * @return SimpleXMLElement
	 */
	public function getDoc(): SimpleXMLElement
	{
		return $this->doc;
	}

	/**
	 * Fix relative urls
	 *
	 * @param  string $url
	 * @return string
	 */
	protected function getByRelativeUrl(string $url): string 
	{
		if (strpos($url, '://') === false) {

			$url = $this->domain . ($url[0] !== '/' ? "{$url}/" : $url);
		}

		return $url;
	}

	/**
	 * Convert date to ISO8601 format
	 *
	 * @param  int|string $date
	 * @return string
	 */
	protected function pasreDate($date): string
	{
		if (is_int($date) === false) {
			$date = strtotime($date);
		}
		
		return date('c', $date);
	}

	/**
	 * Escape urls
	 *
	 * @param  string $url
	 * @return string
	 */
	protected function escapeUrl(string $url): string
	{
		if ($this->options['escape']) {

			$url = parse_url($url);
			$url['path'] = $url['path'] ?? '';
			$url['query'] = $url['query'] ?? '';

			if ($url['path']) {

				$url['path'] = implode('/', array_map('rawurlencode', explode('/', $url['path'])));
			}

			$url = str_replace(
				['&', "'", '"', '>', '<'], 
				['&amp;', '&apos;', '&quot;', '&gt;', '&lt;'],
				$url['scheme'] . "://{$url['host']}{$url['path']}" . ($url['query'] ? "?{$url['query']}" : '')
			);
		}

		return $url;
	}
}
