class Notification
  include DataMapper::Resource
  property :id, Serial

  belongs_to :account
  belongs_to :site

  has n, :links

  property :token, String, :index => true

  property :text, Text
  property :html, Text

  property :created_at, DateTime, :index => true
  property :updated_at, DateTime

  def url
    "#{SiteConfig.base_url}/notification/#{token}"
  end
end