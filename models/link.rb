class Link
  include DataMapper::Resource
  property :id, Serial

  property :href, String, :length => 512
  property :verified, Boolean
  property :token, String, :length => 20, :index => true
  property :is_private, Boolean, :default => false

  property :html, Text
  property :url, String, :length => 256
  property :author_url, String, :length => 256
  property :author_name, String, :length => 256
  property :author_photo, String, :length => 256
  property :name, Text
  property :summary, Text
  property :content, Text
  property :content_text, Text

  property :photo, Text
  property :video, Text
  property :audio, Text

  property :published, DateTime
  property :published_offset, Integer
  property :published_ts, Integer
  property :syndication, Text
  property :swarm_coins, Integer

  property :type, String
  property :is_direct, Boolean, :default => true
  property :sentence, Text
  property :sentence_html, Text

  belongs_to :page
  belongs_to :site
  belongs_to :account

  belongs_to :notification, :required => false

  property :deleted, Boolean, :default => false

  property :protocol, String, :length => 30 # webmention or pingback

  property :created_at, DateTime
  property :updated_at, DateTime

  def snippet
    if content
      stripped = Sanitize.fragment(content).strip
      if stripped
        snippet = stripped.gsub "\n", ' '
      else
        snippet = ''
      end
    else
      if content_text
        snippet = content_text.gsub "\n", ' '
      else
        snippet = ''
      end
    end
    if snippet.length > 80
      snippet = snippet[0, 80] + '...'
    end
    snippet
  end

  def has_author_info
    !author_name.blank? || !author_url.blank? || !author_photo.blank?
  end

  def author_text(fallback="someone")
    if !author_name.blank?
      author_name
    elsif !author_url.blank?
      author_url
    else
      fallback
    end
  end

  def author_html(fallback_text="someone", fallback_url=nil)
    # The ruby mf2 parser doesn't resolve relative URLs, so the author URL might be relative.
    # Use Ruby's "join" with the page href to get the absolute URL.
    if !author_url.blank?
      begin
        absolute = URI.join(href,author_url)
      rescue => e
        absolute = author_url
      end
      if !author_name.blank?
        "<a href=\"#{absolute}\">#{author_name}</a>"
      else
        "<a href=\"#{absolute}\">#{absolute}</a>"
      end
    else
      if !author_name.blank?
        if fallback_url
          "<a href=\"#{fallback_url}\">#{author_name}</a>"
        else
          author_name
        end
      else
        if fallback_url
          "<a href=\"#{fallback_url}\">#{fallback_text}</a>"
        else
          fallback_text
        end
      end
    end
  end

  def name_truncated
    return "" unless name

    snippet = Sanitize.fragment(name).strip.gsub("\n", ' ').gsub(Regexp.new('\s+'),' ')
    # TODO: better ellipsizing
    if snippet.length > 80
      snippet = snippet[0, 80] + '...'
    end

    snippet
  end

  def syndications
    return nil if syndication.blank?
    return JSON.parse syndication
  end

  def published_date
    return nil if published.blank?
    date = published
    if !published_offset.nil?
      date = date.new_offset(Rational(published_offset, 86400))
    end
    date
  end

  def absolute_url
    if url.blank?
      href
    else
      Microformats2::AbsoluteUri.new(url, base: href).absolutize
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

  def mf2_relation_class
    case self.type
    when 'repost'
      'u-repost-of'
    when 'like'
      'u-like-of'
    when 'reply'
      'u-in-reply-to'
    when 'bookmark'
      'u-bookmark-of'
    else
      'u-mention-of'
    end
  end
end
