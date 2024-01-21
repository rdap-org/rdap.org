#!/usr/bin/perl
use Cwd;
use Data::Mirror qw(mirror_file);
use DateTime;
use IO::File;
use JSON;
use open qw(:utf8);
use feature qw(say);
use utf8;
use strict;

$Data::Mirror::TTL_SECONDS = 3600;

my $dir = $ARGV[0] || getcwd();

if (!-e $dir || !-d $dir) {
	printf(STDERR "Error: %s doesn't exist, please create it first\n");
	exit(1);
}

my $json = JSON->new->pretty->canonical;

say STDERR 'updating registrar RDAP data...';

my $all = {
  'rdapConformance' => [ 'rdap_level_0' ],
  'entitySearchResults' => [],
};

my $file = mirror_file('https://www.icann.org/en/accredited-registrars');
say STDERR 'retrieved registrar list';

my $doc = XML::LibXML->load_html(
    'location'          => $file,
    'suppress_warnings' => 1,
    'recover'           => 2,
    'huge'              => 1,
    'encoding'          => 'UTF-8',
    'no_blanks'         => 1,   
    'no_cdata'          => 1,
);

my $data = (grep { 'serverApp-state' eq $_->getAttribute('id') && 'application/json' eq $_->getAttribute('type') } $doc->getElementsByTagName('script'))[0]->childNodes->item(0)->data;
$data =~ s/\&q;/"/g;

my $object = $json->decode($data);

my $rars = $object->{'accredited-registrars-{"languageTag":"en","siteLanguageTag":"en","slug":"accredited-registrars"}'}->{'data'}->{'accreditedRegistrarsOperations'}->{'registrars'};

say STDERR 'generating RDAP records...';

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

	$data->{'notices'} = [
		{
			'title'	=> 'About This Service',
			'description' => [
				'Please note that this RDAP service is NOT provided by the IANA.',
				'',
				'For more information, please see https://about.rdap.org',
			],
		}
	];

	$data->{'events'} = [ {
		'eventAction' => 'last update of RDAP database',
		'eventDate' => DateTime->now->iso8601,
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

	my $file = IO::File->new;
	if (!$file->open($jfile, '>:utf8')) {
		printf(STDERR "Cannot write to '%s': %s\n", $jfile, $!);
		next;

	} else {
		$file->print($json->encode($data));
		$file->close;

        say STDERR sprintf('wrote %s', $jfile);
	}

    $all->{'notices'} = $data->{'notices'} unless (defined($all->{'notices'}));
    delete($data->{'notices'});
    delete($data->{'rdapConformance'});

    push(@{$all->{'entitySearchResults'}}, $data);
}

#
# write RDAP object to disk
#
my $jfile = sprintf('%s/_all.json', $dir);
my $file = IO::File->new;
if (!$file->open($jfile, '>:utf8')) {
	printf(STDERR "Cannot write to '%s': %s\n", $jfile, $!);
	exit(1);

} else {
	$file->print($json->encode($all));
    $file->close;

    say STDERR sprintf('wrote %s', $jfile);
}

say STDERR 'done';
