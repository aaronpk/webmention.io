class Page
  include DataMapper::Resource
  property :id, Serial

  property :href, String, :length => 512

  belongs_to :account
  belongs_to :site
  has n, :links

  property :type, String
  property :name, Text

  property :created_at, DateTime
  property :updated_at, DateTime

  def name_truncated
    return "" unless name

    snippet = Sanitize.fragment(name).strip.gsub "\n", ' '
    # TODO: better ellipsizing
    if snippet.length > 100
      snippet = snippet[0, 100] + '...'
    end

    snippet
  end
  
end
