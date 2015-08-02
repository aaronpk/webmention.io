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
  property :name, String, :length => 256
  property :content, Text
  property :published, DateTime
  property :published_ts, Integer

  property :type, String
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
    else
      author_url
    end
  end

  def author_html
    if author_name
      "<a href=\"#{author_url}\">#{author_name}</a>"
    elsif author_url
      "<a href=\"#{author_url}\">#{author_url}</a>"
    else
      nil
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
