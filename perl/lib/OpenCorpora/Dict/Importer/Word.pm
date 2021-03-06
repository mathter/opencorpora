package OpenCorpora::Dict::Importer::Word;

use strict;
use warnings;
use utf8;

use constant DEBUG => 0;

sub new {
    #a Word may be created either with a set of forms or without one
    print STDERR "Creating Word\n" if DEBUG;
    my ($class, $ref, $para_no, $importer) = @_;
 
    my $self = {};
    $self->{LEMMA} = undef;
    $self->{FORMS} = undef;
    $self->{APPLIED_RULES} = undef;
    $self->{PARADIGM_NO} = $para_no;
    $self->{LINKS} = undef;
    $self->{IMPORTER} = $importer;

    if (!defined $ref) {
        #no forms given
        bless $self;
        return $self;
    }

    my @forms = @$ref;
    #adding forms
    for my $form_string(@forms) {
        if ($form_string =~ /^(\S+)\t([A-Za-zА-Яа-я0-9Ёё\-_]+),?\s?(\S+)?,?\s?(\S+)?/) {
            if (!$self->{LEMMA}) {
                $self->{LEMMA} = to_lower($1);
            }
            my $form_ref;
            $form_ref->{TEXT} = to_lower($1);
            $form_ref->{GRAMMEMS} = undef;
            my @all_gram = ($2);
            push @all_gram, split (/,/, $3) if $3;
            push @all_gram, split (/,/, $4) if $4;
            map { $_ =~ s/\s//g; } @all_gram;
            $form_ref->{GRAMMEMS} = \@all_gram;
            push @{$self->{FORMS}}, $form_ref;
        }
        else {
            die "Bad string: $form_string";
        }
    }
 
    bless $self;
    #return is implicit in bless
}
sub form_has_gram {
    my $self = shift;
    my $form = shift;
    my $search = shift;
    if ($search =~ /~\/(.+)\//) {
        if ($form->{TEXT} =~ /$1/i) {
            return 1;
        }
        return 0;
    }
    if ($search =~ /@(.+)/) {
        if (exists $self->{IMPORTER}->{WORD_LISTS}->{$1}->{$form->{TEXT}}) {
            return 1;
        }
        return 0;
    }
    for my $gram(@{$form->{GRAMMEMS}}) {
        if ($gram eq $search) {
            return 1;
        }
    }
    return 0;
}
sub count_form_has_gram_set {
    my $self = shift;
    my $form = shift;
    my @search = @{shift()};
    my $count = 0;
    for my $search(@search) {
        $self->form_has_all_grams($form, $search) && $count++;
    }
    return $count;
}
sub form_has_all_grams {
    my $self = shift;
    my $form = shift;
    my @search = @{shift()};
    print STDERR "  testing if ".$form->{TEXT}." (".join(',', @{$form->{GRAMMEMS}}).") has all of ".join(',', @search).'.. ' if DEBUG;
    my $neg = 0; #whether we have a '!'
    for my $gram(@search) {
        $neg = ($gram =~ s/^\!//);
        if (($neg && $self->form_has_gram($form, $gram)) ||
            (!$neg && !$self->form_has_gram($form, $gram))) {
            print STDERR "It doesn't.\n" if DEBUG;
            return 0;
        }
    }
    print STDERR "It does!\n" if DEBUG;
    return 1;
}
sub form_has_any_gram {
    my $self = shift;
    my $form = shift;
    my @search = @{shift()};
    print STDERR "  testing if ".$form->{TEXT}." has any of ".join(',', @search).'.. ' if DEBUG;
    my $neg = 0; #whether we have a '!'
    for my $gram(@search) {
        $neg = ($gram =~ s/^\!//);
        if ((!$neg && $self->form_has_gram($form, $gram)) ||
            ($neg && !$self->form_has_gram($form, $gram))) {
            print STDERR "It does!\n" if DEBUG;
            return 1;
        }
    }
    print STDERR "It doesn't.\n" if DEBUG;
    return 0;
}
sub change_grammems {
    my $self = shift;
    my $in = shift;
    my %in; $in{$_} = 1 for (@$in);
    my $out = shift;
    for my $form(@{$self->{FORMS}}) {
        if ($self->form_has_all_grams($form, $in)) {
            my @new_grams;
            my %new_grams;
            for my $gram(@{$form->{GRAMMEMS}}) {
                if (!exists $in{$gram}) {
                    push(@new_grams, $gram);
                    $new_grams{$gram} = 1;
                }
            }
            for(@$out) {
                push(@new_grams, $_) unless exists $new_grams{$_};
                $new_grams{$_} = 1;
            }
            $form->{GRAMMEMS} = \@new_grams;
        }
    }
}
sub split_lemma {
    my $self = shift;
    my $action = shift;
    my @grammems = @{$action->{GRAMMEMS_IN}};
    my @new_grammems;
    my %new_words;
    my @out_words;
    my $has_aster = 0;
    my $ok;
    print STDERR "    split with (".join(',', @grammems).")\n" if DEBUG;
    #check if there is '*'
    for my $gram(@grammems) {
        if ($gram eq '*') {
            $has_aster = 1;
            last;
        }
    }
    #making @grammems an array of arrays
    for my $gram(@grammems) {
        my @t = split /\&/, $gram;
        map { $_ =~ s/^\s+//; $_ =~ s/\s+$//; } @t;
        for my $i(0..$#t) {
            delete $t[$i] if $t[$i] eq '';
        }
        push @new_grammems, \@t;
    }
    #splitting itself
    for my $form(@{$self->{FORMS}}) {
        $ok = 0;
        #check if any form has more than one of @grammems
        if ($self->count_form_has_gram_set($form, \@new_grammems) > 1) {
            printf STDERR "[rule %d, line %d] Warning: Form '%s' has several grammems among (%s), cannot split, skipping\n",
                $action->{RULE_NO}, $action->{STRING_NO}, $form->{TEXT}, join(',', @grammems);
            return [$self];
        }
        for my $gram(@new_grammems) {
            if ($self->form_has_all_grams($form, $gram)) {
                push @{$new_words{$gram}}, $form;
                $ok = 1;
                last;
            }
        }
        if (!$ok) {
            if ($has_aster) {
                push @{$new_words{'*'}},  $form;
            }
            else {
                printf STDERR "[rule %d, line %d] Warning: Form '%s' has no grammems among (%s), cannot split, skipping\n",
                    $action->{RULE_NO}, $action->{STRING_NO}, $form->{TEXT}, join(',', @grammems);
                return [$self];
            }
        }
    }
    #split successful, now we should construct Word's
    my $k;
    for my $i(0..$#grammems) {
        $k = $new_grammems[$i];
        next unless exists $new_words{$k};
        my $word = OpenCorpora::Dict::Importer::Word->new(undef, -1, $self->{IMPORTER});
        my @forms = @{$new_words{$k}};
        $word->{LEMMA} = $forms[0]->{TEXT};
        $word->{FORMS} = \@forms;
        $out_words[$i] = $word unless $word->is_to_delete();
    }
    return \@out_words;
}
sub generate_paradigm {
    my $self = shift;
    my $action = shift;
    if ($self->get_form_count(1) > 1) {
        printf STDERR "[rule %d, line %d] Warning: Word '%s' has more than one form, cannot generate full paradigm, skipping\n",
            $action->{RULE_NO}, $action->{STRING_NO}, $self->{LEMMA};
        return;
    }
    my @gram = @{$action->{GRAMMEMS_IN}};
    for my $gr(@gram) {
        $self->generate_paradigm_plain($gr);
    }
}
sub generate_paradigm_plain {
    my $self = shift;
    my @gram = @{shift()};
    my @new_forms;
    for my $form(@{$self->{FORMS}}) {
        next if $self->form_has_gram($form, '_del');
        for my $g(@gram) {
            my $new_form = {};
            $new_form->{TEXT} = $form->{TEXT};
            my @new_grams = (@{$form->{GRAMMEMS}}, $g);
            $new_form->{GRAMMEMS} = \@new_grams;
            push @new_forms, $new_form;
        }
    }
    $self->{FORMS} = \@new_forms;
}
sub to_lower {
    my $s = shift;
    $s =~ tr/[А-ЯЁ]/[а-яё]/;
    return $s;
}
sub get_form_count {
    my $self = shift;
    my $ignore_del = shift;

    if (!$ignore_del) {
        return scalar @{$self->{FORMS}};
    }

    # we should ignore any forms that have grammeme _del
    my $cnt = 0;
    for my $form(@{$self->{FORMS}}) {
        ++$cnt unless $self->form_has_gram($form, '_del');
    }
    return $cnt;
}
sub rule_applied {
    my $self = shift;
    my $rule = shift;
    for my $r(@{$self->{APPLIED_RULES}}) {
        if ($r == $rule->{ID}) {
            return 1;
        }
    }
    return 0;
}
sub to_string {
    my $self = shift;
    my $out = 'PARA '.($self->{PARADIGM_NO} ? $self->{PARADIGM_NO} : '-1')."\n";
    for my $form(@{$self->{FORMS}}) {
        next if $self->form_has_gram($form, '_del');
        $out .= $form->{TEXT}."\t".join(',', @{$form->{GRAMMEMS}})."\n";
    }
    for my $lnk(@{$self->{LINKS}}) {
        next unless defined $lnk;
        $out .= "link '".$$lnk[1]."' to ".$$lnk[0]."\n";
    }
    return $out;
}
sub to_xml {
    my $self = shift;
    my $ref_bad_gram = $self->{IMPORTER}->{BAD_LEMMA_GRAMMEMS};

    my $out = '<dr><l t="'.$self->{LEMMA}.'">';
    my @lgram = @{$self->get_lemma_grammems($ref_bad_gram)};
    my %lgram;
    for my $gr(@lgram) {
        $out .= "<g v=\"$gr\"/>";
        $lgram{$gr} = 1;
    }
    $out .= '</l>';
    for my $form(@{$self->{FORMS}}) {
        next if $self->form_has_gram($form, '_del');
        $out .= '<f t="'.$form->{TEXT}.'">';
        for my $gr(@{$form->{GRAMMEMS}}) {
            $out .= "<g v=\"$gr\"/>" unless exists $lgram{$gr};
        }
        $out .= '</f>';
    }
    $out .= '</dr>';
    return $out;
}
sub get_all_grammems {
    my $self = shift;
    my %all = ();
    for my $form(@{$self->{FORMS}}) {
        #discard forms to be deleted
        next if $self->form_has_any_gram($form, ['_del']);
        for my $gram(@{$form->{GRAMMEMS}}) {
            ++$all{$gram};
        }
    }
    return \%all;
}
sub get_lemma_grammems {
    my $self = shift;
    my %bad = %{shift()};
    my %order = ();

    if (defined $self->{IMPORTER}->{GRAM_ORDER}) {
        %order = %{$self->{IMPORTER}->{GRAM_ORDER}};
    }
    
    my %grams = %{$self->get_all_grammems()};
    my @out;
    my $num = 0;
    #we should ignore forms that should be deleted
    for my $form(@{$self->{FORMS}}) {
        ++$num unless $self->form_has_any_gram($form, ['_del']);
    }

    my $pos = '';

    for my $gr(keys %grams) {
        if ($gr =~ /^[A-Z]+$/) {
            $pos = $gr;
            last;
        }
    }
    for my $gr(keys %grams) {
        if ($grams{$gr} >= $num && !exists $bad{'*'}{$gr} && !exists $bad{$pos}{$gr}) {
            push @out, $gr;
        }
    }

    if (%order) {
        @out = sort {$order{$a} <=> $order{$b}} @out;
    }

    return \@out;
}
sub sort_grammems {
    my $self = shift;
    my $ref = $self->{IMPORTER}->{GRAM_ORDER};

    for my $form(@{$self->{FORMS}}) {
        $self->sort_form_grammems($form, $ref);
    }
}
sub sort_form_grammems {
    my $self = shift;
    my $form = shift;
    my %order = %{shift()};

    for my $gram(@{$form->{GRAMMEMS}}) {
        if (!exists $order{$gram}) {
            $order{$gram} = scalar keys %order;
        }
    }

    my @new_gram = sort {$order{$a} <=> $order{$b}} @{$form->{GRAMMEMS}};
    $form->{GRAMMEMS} = \@new_gram;
}
sub is_to_delete {
    my $self = shift;

    for my $form(@{$self->{FORMS}}) {
        return 0 unless $self->form_has_gram($form, '_del');
    }
    return 1;
}

1;
