class Link
  include DataMapper::Resource
  property :id, Serial

  property :href, String, :length => 512
  property :verified, Boolean

  property :html, Text
  property :url, String, :length => 256
  property :author_url, String, :length => 256
  property :author_name, String, :length => 256
  property :author_photo, String, :length => 256
  property :name, Text
  property :summary, Text
  property :content, Text
  property :published, DateTime
  property :published_ts, Integer

  property :type, String
  property :is_direct, Boolean, :default => true
  property :sentence, Text
  property :sentence_html, Text

  belongs_to :page
  belongs_to :site

  belongs_to :notification, :required => false

  property :created_at, DateTime
  property :updated_at, DateTime

  def author_text
    if author_name
      author_name
    elsif author_url
      author_url
    else
      "someone"
    end
  end

  def author_html
    # The ruby mf2 parser doesn't resolve relative URLs, so the author URL might be relative.
    # Use Ruby's "join" with the page href to get the absolute URL.
    if author_url
      absolute = URI.join(href,author_url)
      if author_name
        "<a href=\"#{absolute}\">#{author_name}</a>"
      elsif author_url
        "<a href=\"#{absolute}\">#{absolute}</a>"
      else
        "someone"
      end
    else
      if author_name
        author_name
      else
        "someone"
      end
    end
  end

  def name_truncated
    return "" unless name

    snippet = Sanitize.fragment(name).strip.gsub("\n", ' ').gsub(Regexp.new('\s+'),' ')
    # TODO: better ellipsizing
    if snippet.length > 100
      snippet = snippet[0, 100] + '...'
    end

    snippet
  end

  def absolute_url
    if url.blank?
      href
    else
      Microformats2::AbsoluteUri.new(href, url).absolutize
    end
  end

  def source
    self.href
  end

  def source_id
    self.id
  end

  def target
    self.page.href
  end

  def target_id
    self.page.id
  end
end
