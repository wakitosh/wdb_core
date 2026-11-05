# frozen_string_literal: true

# Run with Ruby; HTTP is mocked and Cantaloupe is not required.
ENV['WDB_SKIP_AUTH_DIRS'] = 'sample'
ENV['WDB_TOKEN_ONLY'] = 'true'
ENV['WDB_AUTH_CACHE_TTL'] = '0'
require 'minitest/autorun'
require_relative 'delegates'

class Net::HTTP
  class << self
    attr_accessor :mock_authorized, :mock_requests
  end

  def request(_request)
    Net::HTTP.mock_requests += 1
    response = Net::HTTPOK.new('1.1', '200', 'Mock response')
    def response.body
      JSON.generate('authorized' => Net::HTTP.mock_authorized)
    end
    response
  end
end

class DelegateSecurityTest < Minitest::Test
  def setup
    Net::HTTP.mock_authorized = false
    Net::HTTP.mock_requests = 0
    @delegate = CustomDelegate.new
    @delegate.context = {
      'identifier' => 'wdb/restricted/book/1.ptif',
      'request_uri' => 'https://images.example.org/iiif/3/wdb%2Frestricted%2Fbook%2F1.ptif/full/100,/0/default.jpg',
      'client_ip' => '203.0.113.42',
      'cookies' => {},
      'request_headers' => {},
    }
  end

  def test_private_tile_requires_authorization
    refute @delegate.pre_authorize
    assert_equal 1, Net::HTTP.mock_requests
  end

  def test_info_json_query_cannot_bypass_authorization
    @delegate.context['request_uri'] += '?probe=info.json'
    refute @delegate.pre_authorize
    assert_equal 1, Net::HTTP.mock_requests
  end

  def test_public_directory_in_query_cannot_bypass_authorization
    @delegate.context['request_uri'] += '?probe=/sample/'
    refute @delegate.pre_authorize
    assert_equal 1, Net::HTTP.mock_requests
  end

  def test_private_info_json_requires_authorization
    @delegate.context['request_uri'] = 'https://images.example.org/iiif/3/private/info.json'
    refute @delegate.pre_authorize
  end

  def test_loopback_proxy_does_not_bypass_authorization
    @delegate.context['client_ip'] = '127.0.0.1'
    refute @delegate.pre_authorize
  end

  def test_explicit_public_identifier_segment_is_allowed
    @delegate.context['identifier'] = 'wdb/sample/book/1.ptif'
    assert @delegate.pre_authorize
    assert_equal 0, Net::HTTP.mock_requests
  end

  def test_ambiguous_identifier_does_not_get_public_exception
    @delegate.context['identifier'] = 'wdb/sample/../restricted/book/1.ptif'
    refute @delegate.pre_authorize
  end

  def test_authorized_backend_response_allows_private_image
    Net::HTTP.mock_authorized = true
    assert @delegate.pre_authorize
  end
end
