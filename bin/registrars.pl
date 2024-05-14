#!/usr/bin/perl
use Cwd;
use Data::Mirror qw(mirror_file);
use DateTime;
use Encode;
use File::Slurp;
use JSON::XS;
use open qw(:utf8);
use feature qw(say);
use utf8;
use strict;

say STDERR 'updating registrar RDAP data...';

my $NOTICE = {
    'title' => 'About This Service',
    'description' => [
        'Please note that this RDAP service is NOT provided by the IANA.',
        '',
        'For more information, please see https://about.rdap.org',
    ],
};

my $updateTime = DateTime->now->iso8601;

$Data::Mirror::TTL_SECONDS = 3600;

my $dir = $ARGV[0] || getcwd();

if (!-e $dir || !-d $dir) {
	printf(STDERR "Error: %s doesn't exist, please create it first\n");
	exit(1);
}

my $json = JSON::XS->new->utf8->pretty->canonical;

my $all = {
  'rdapConformance' => [ 'rdap_level_0' ],
  'notices' => [ $NOTICE ],
  'entitySearchResults' => [],
};

my $file = mirror_file('https://www.icann.org/en/accredited-registrars');
say STDERR 'retrieved registrar list, attempting to parse';

my $doc = XML::LibXML->load_html(
    'location'          => $file,
    'suppress_warnings' => 1,
    'recover'           => 2,
    'huge'              => 1,
    'encoding'          => 'UTF-8',
    'no_blanks'         => 1,   
    'no_cdata'          => 1,
);

say STDERR 'searching for embedded JSON...';
my $data = (grep { 'ng-state' eq $_->getAttribute('id') && 'application/json' eq $_->getAttribute('type') } $doc->getElementsByTagName('script'))[0]->childNodes->item(0)->data;
$data =~ s/\&q;/"/g;

my $object = $json->decode(Encode::encode_utf8($data));

my $rars = $object->{'accredited-registrars-{"languageTag":"en","siteLanguageTag":"en","slug":"accredited-registrars"}'}->{'data'}->{'accreditedRegistrarsOperations'}->{'registrars'};

say STDERR 'generating RDAP records for registrars...';

foreach my $rar (sort { $a->{'ianaNumber'} <=> $b->{'ianaNumber'} } @{$rars}) {
    my $id = $rar->{'ianaNumber'};

	my $data = {
		'objectClassName' => 'entity',
		'handle' => sprintf('%s-iana', $id),
		'publicIds' => [ { 'type' => 'IANA Registrar ID', 'identifier' => int($id) }],
		'rdapConformance' => [ 'rdap_level_0' ],
		'status' => [ 'active' ],
		'vcardArray' => [ 'vcard', [ [
			'version',
			{},
			'text',
			'4.0',
		] ] ],
	};

	if ($rar->{'publicContact'}->{'name'}) {
		push(@{$data->{'vcardArray'}->[1]}, [ 'fn', {}, 'text', $rar->{'publicContact'}->{'name'} ]);
		push(@{$data->{'vcardArray'}->[1]}, [ 'org', {}, 'text', $rar->{'name'} ]);

	} else {
		push(@{$data->{'vcardArray'}->[1]}, [ 'fn', {}, 'text', $rar->{'name'} ]);

	}

	if ($rar->{'publicContact'}->{'phone'}) {
		$rar->{'publicContact'}->{'phone'} =~ s/^="//g;
		$rar->{'publicContact'}->{'phone'} =~ s/"$//g;
		push(@{$data->{'vcardArray'}->[1]}, [ 'tel', {} , 'text', $rar->{'publicContact'}->{'phone'} ]);
	};

	push(@{$data->{'vcardArray'}->[1]}, [ 'email', {} , 'text', $rar->{'publicContact'}->{'email'} ]) if ($rar->{'publicContact'}->{'email'});
	push(@{$data->{'vcardArray'}->[1]}, [ 'adr', {} , 'text', [ '', '', '', '', '', '', $rar->{'country'} ] ]) if ($rar->{'country'});

	if ($rar->{'url'}) {
		push(@{$data->{'links'}}, {
			'title' => "Registrar's Website",
			'rel' => 'related',
			'href' => $rar->{'url'}
		});
	}

	$data->{'notices'} = [ $NOTICE ];

	$data->{'events'} = [ {
		'eventAction' => 'last update of RDAP database',
		'eventDate' => $updateTime,
	} ];

	#
	# add some links
	#
	push(@{$data->{'links'}}, {
		'title'	=> 'About RDAP',
		'rel'	=> 'related',
		'href'	=> 'https://about.rdap.org',
	});

	#
	# write RDAP object to disk
	#
	my $jfile = sprintf('%s/%s.json', $dir, $data->{'handle'});

    if (!write_file($jfile, {'binmode' => ':utf8'}, $json->encode($data))) {
        printf(STDERR "Unable to write data to '%s': %s\n", $jfile, $!);
        exit(1);
    }

    delete($data->{'notices'});
    delete($data->{'rdapConformance'});

    push(@{$all->{'entitySearchResults'}}, $data);
}

say STDERR 'RDAP records generated, writing registrar search result file...';

#
# write RDAP object to disk
#
my $jfile = sprintf('%s/_all.json', $dir);

if (!write_file($jfile, {'binmode' => ':utf8'}, $json->encode($all))) {
    printf(STDERR "Unable to write to '%s': %s\n", $jfile, $!);
    exit(1);

} else {
    say STDERR sprintf('wrote %s', $jfile);

}

say STDERR 'done';
