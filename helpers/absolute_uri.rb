# Copied from Microformats2
# https://github.com/microformats/microformats-ruby/blob/main/lib/microformats/absolute_uri.rb

module AbsoluteUri
  class AbsoluteUri
    attr_accessor :base, :relative

    def initialize(relative, base: nil)
      @base = base
      @relative = relative
      @base = base.strip unless base.nil?
      @relative = relative.strip unless relative.nil?
    end

    def absolutize
      return relative if base.nil?
      return base if relative.nil? || relative == ''
      return relative if relative =~ %r{^https?://}
      return base + relative if relative =~ /^#/

      uri = URI.parse(relative)
      uri = URI.join(base.to_s, relative.to_s) if base && !uri.absolute?

      uri.normalize!
      uri.to_s
    rescue URI::BadURIError, URI::InvalidURIError
      relative.to_s
    end
  end
end