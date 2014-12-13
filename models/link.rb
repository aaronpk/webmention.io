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

  property :created_at, DateTime
  property :updated_at, DateTime
end
