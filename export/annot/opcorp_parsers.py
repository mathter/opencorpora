#!/usr/bin/env python2
# -*- coding: utf-8 -*-

import re
from xml.sax.xmlreader import AttributesImpl
import codecs
import os

import no_homonymy_constants
import opcorp_basic_parsers

"""
methods for removing extra grammemes from tokens
"""
class OpcorpTokenVariantRemover(opcorp_basic_parsers.OpcorpBasicParser):
    

    def __init__(self, out_filepath, tokens_with_agreement, encoding):
        super().__init__(tokens_with_agreement.keys())

        self.file = None
        
        #save input parameters
        self.out_filepath = out_filepath
        self.tokens_with_agreement = tokens_with_agreement
        self.encoding = encoding        

        #save the id of the token we've found
        self.current_token = None
        self.current_token_text = None
        
        # the flag showing if we've written the starting tag
        #if we've skipped the starting tag, we'll also skip the ending tag
        self.is_start_tag_written = False
        #tracks if the <v> or <l> tags have been started
        self.is_start_variant_tag_written = False
        
        #the flag showing if the <g> tag is the first for the lexeme
        self.is_first_grammeme = False

        #collect the max set of grammemes for each variant
        self.tokens_max_variant_arrays = {}
        
        self._new_file()
        
        
        self.is_doubtful_variant = False
        self.current_token_grammemes = set()
    
    #generates the <g> tag and the <v><l> structure if necessary
    def _write_grammeme_tag(self, name, attrs, is_to_generate_wrapper):
        if is_to_generate_wrapper:
            self.is_start_variant_tag_written = True
            self.file.write(self._gen_start_tag(self.TAG_VARIANT, None))
            self.file.write(self._gen_start_tag(self.TAG_LEXEME, self.lexeme_attrs))
        self.file.write(self._gen_start_tag(name, attrs))
            
            
    def _add_to_current_grammeme_set(self, grammeme, is_first_grammeme):
        if is_first_grammeme:
            self.current_set = set()
        self.current_set.add(grammeme)
        

    def startElement(self, name, attrs):
        self.is_start_tag_written = True
        
        
        #check if the token has annotators' decisions     
        if name == self.TAG_TOKEN \
                and not self._are_tokens_found():
            
            self.file.write(self._gen_start_tag(name, attrs))
            fid = attrs.get('id')

            self.current_token_grammemes = set()
            

            if self.tokens_with_agreement.get(fid):
                self.current_token = fid
                self.current_token_text = attrs.get('text')
                self.ids_left -= 1
            
        #we skip the <v> or <l> tags because we may write them later
        elif self.current_token and name == self.TAG_VARIANT:
            self.is_doubtful_variant = True
            self.is_first_grammeme = True
            self.is_start_variant_tag_written = False
            self.current_set = None
            
            
        
        #we skip the <v> or <l> tags because we may write them later
        elif self.current_token and name == self.TAG_LEXEME:
            self.is_doubtful_variant = True
            self.lexeme_attrs = attrs
            self.is_start_variant_tag_written = False
            
            
                
        elif self.current_token and (name == self.TAG_GRAMMEME):
            grammeme_value = attrs.get("v")
            
  
            self.current_token_grammemes.add(grammeme_value)
            
          
            
            pool_types = self.tokens_with_agreement[self.current_token]
  
            for pool_type, decision in pool_types.items():
                if pool_type is None:
                    continue
                pool_variants = set(re.split('\\s*[&@]\\s*', pool_type))
                decisions = set(re.split('\\s*&\\s*', decision))
                
                
                
                #if the annotators have chosen the Other variant
                #we write UNKNOWN grammemes instead of all grammemes
                if (no_homonymy_constants.DECISION_UNKNOWN in decisions):
                    self._add_to_current_grammeme_set(no_homonymy_constants.DECISION_UNKNOWN, self.is_first_grammeme)
                    
                    new_attrs = AttributesImpl({self.TAG_VARIANT:no_homonymy_constants.DECISION_UNKNOWN})
                    new_lexeme_attrs = AttributesImpl({'t':self.current_token_text.lower(), 'id':'0'})
                    self.lexeme_attrs = new_lexeme_attrs
                    
                    self._write_grammeme_tag(name, new_attrs, self.is_first_grammeme)
                    self.is_first_grammeme = False
                    
                #the grammeme should be written in two cases:
                #-there are no doubts about it and it hasn't been in a pool
                #-it has been chosen by the annotators  
                elif (grammeme_value not in pool_variants) or (grammeme_value in decisions):
                    self._add_to_current_grammeme_set(grammeme_value, self.is_first_grammeme)
                    
                    self._write_grammeme_tag(name, attrs, self.is_first_grammeme)
                    self.is_first_grammeme = False
                    
                #the grammeme won't be written
                else:
                    self.is_start_tag_written = False
                    
            
        else:
            self.file.write(self._gen_start_tag(name, attrs))
            

    def endElement(self, name): 
        #the decisions and the original values have no intersection
        #(an unambiguous token has been disambiguated)
        #then we use the decisions from the file
        if (name == self.TAG_TFR) and (not self.is_start_tag_written):

            pool_types = self.tokens_with_agreement[self.current_token]
  
            for pool_type, decision in pool_types.items():
                if pool_type is None:
                    continue
                pool_variants = set(re.split('\\s*[&@]\\s*', pool_type))
                decisions = set(re.split('\\s*&\\s*', decision))
            
            
                if self.current_token_grammemes.isdisjoint(decisions):
                    print("unambiguous token has been disambiguated: id:%s" % (self.current_token))
                        
                        
                        
                    for current_value in self.current_token_grammemes:
                        self._add_to_current_grammeme_set(current_value, self.is_first_grammeme)
                            
                        new_attrs = AttributesImpl({"v" : current_value})
                        self._write_grammeme_tag(self.TAG_GRAMMEME, new_attrs, self.is_first_grammeme)
                            
                        self.is_first_grammeme = False
                            
                        self.file.write(self._gen_end_tag(self.TAG_GRAMMEME))
                     
                    self.file.write(self._gen_end_tag(self.TAG_LEXEME))   
                    self.file.write(self._gen_end_tag(self.TAG_VARIANT))
  
        if not name in [self.TAG_VARIANT, self.TAG_LEXEME, self.TAG_GRAMMEME] \
            or (name == self.TAG_GRAMMEME and self.is_start_tag_written) \
            or (name in [self.TAG_VARIANT, self.TAG_LEXEME] and (not self.is_doubtful_variant or self.is_start_variant_tag_written)):
            
            self.file.write(self._gen_end_tag(name))
    
            #if the <v> tag ends, we collect all the grammemes from this variant
            #to use it later for normalizing
            if name == self.TAG_VARIANT and self.is_start_variant_tag_written:
                if not self.current_token in self.tokens_max_variant_arrays:
                    self.tokens_max_variant_arrays[self.current_token] = dict()
 
                
                current_grammeme_set = frozenset(self.current_set.copy())
                current_lexeme_attrs = tuple(self.lexeme_attrs.items())
                
                #we create an array of maximum sets of grammemes
                #to delete the grammeme sets which are comprised by larger sets
                
                is_subset = False
                other_subsets_to_remove = []
                for another_set, another_lexeme_attrs in self.tokens_max_variant_arrays[self.current_token].items():
                    #this grammeme set is comprised by another set
                    if current_grammeme_set.issubset(another_set):
                        is_subset = True
                        break
                    if current_grammeme_set.issuperset(another_set):
                        other_subsets_to_remove.append(another_set)
                        
                if not is_subset:
                    self.tokens_max_variant_arrays[self.current_token][current_grammeme_set] = set()
                    self.tokens_max_variant_arrays[self.current_token][current_grammeme_set].add(current_lexeme_attrs)
                #the grammemes may be the same but the lexeme may be different
                elif current_grammeme_set in self.tokens_max_variant_arrays[self.current_token]:
                    self.tokens_max_variant_arrays[self.current_token][current_grammeme_set].add(current_lexeme_attrs)
                    
                
                for another_subset_to_remove in other_subsets_to_remove:
                    self.tokens_max_variant_arrays[self.current_token].pop(another_subset_to_remove, None)
                
            
        if name == self.TAG_TOKEN and self.current_token:
            self.current_token = None    
            
        elif name == self.TAG_VARIANT and self.is_doubtful_variant:
            self.is_doubtful_variant = False
        
        elif name == 'annotation':
            self._close_file()

"""
methods for normalizing variants whose grammemes are in other sets
"""
class OpcorpTokenNormalizer(opcorp_basic_parsers.OpcorpBasicParser):    
    def __init__(self, out_filepath, tokens_max_variant_arrays, encoding, grammeme_file):
        super().__init__(tokens_max_variant_arrays.keys())

        self.file = None
        
        #save input parameters
        self.out_filepath = out_filepath
        self.encoding = encoding
        self.tokens_max_variant_arrays = tokens_max_variant_arrays
    
        # the flag showing if we've written the starting tag
        #if we've skipped the starting tag, we'll also skip the ending tag
        self.is_start_tag_written = False


        self.current_token = None
		
        
        self.is_start_variant_tag_written = False
        
        #the flag showing if the <g> tag is the first for the lexeme
        self.is_first_grammeme = False


        self.current_set = None

        self.grammeme_list = self._initialize_grammeme_list(grammeme_file)
        
        self._new_file()

    def _initialize_grammeme_list(self, grammeme_file):
        grammeme_list = []
        with codecs.open(grammeme_file, 'r', 'utf-8') as fin:
            for line in fin:
                grammeme_list.append(line.split('\t')[1])
        return grammeme_list

    def _grammeme_sort(self, grammeme):
        if grammeme in self.grammeme_list:
            return self.grammeme_list.index(grammeme)
        return -1
        
    def _write_grammeme_set(self, lex_attrs_set, current_grammemes):
        for lexeme_attrs_tuple in lex_attrs_set:
            self.file.write(self._gen_start_tag(self.TAG_VARIANT, None))
            
            lexeme_dict = {}
            for lexeme_attr_item in lexeme_attrs_tuple:
                lexeme_dict[lexeme_attr_item[0]] = lexeme_attr_item[1]
              
            lexeme_attrs = AttributesImpl(lexeme_dict)
            
            self.file.write(self._gen_start_tag(self.TAG_LEXEME, lexeme_attrs))
            
            current_grammemes_sorted = sorted(list(current_grammemes), key = self._grammeme_sort)
            #current_grammemes_sorted = list(current_grammemes)
            for grammeme in current_grammemes_sorted:
                new_attrs = AttributesImpl({"v" : grammeme})
                self.file.write(self._gen_start_tag(self.TAG_GRAMMEME, new_attrs))
                self.file.write(self._gen_end_tag(self.TAG_GRAMMEME))
                
            self.file.write(self._gen_end_tag(self.TAG_LEXEME))
            self.file.write(self._gen_end_tag(self.TAG_VARIANT))
    
    def startElement(self, name, attrs):
        self.is_start_tag_written = True
        
        if name == self.TAG_TOKEN \
                and not self._are_tokens_found():
            
            self.file.write(self._gen_start_tag(name, attrs))
            fid = attrs.get('id')

            if self.tokens_max_variant_arrays.get(fid):
                self.current_token = fid
                self.ids_left -= 1
                
        #we skip the <v> or <l> tags because we may write them later
        elif self.current_token and name == self.TAG_VARIANT:
            self.current_grammemes = set()
        
        elif self.current_token and name == self.TAG_LEXEME:
            self.lexeme_attrs = attrs
                
        elif self.current_token and (name == self.TAG_GRAMMEME):
            grammeme_value = attrs.get(self.TAG_VARIANT)
            self.current_grammemes.add(grammeme_value)
                    
            
        else:
            self.file.write(self._gen_start_tag(name, attrs))
            

    def endElement(self, name):   
        if not name in [self.TAG_VARIANT, self.TAG_LEXEME, self.TAG_GRAMMEME] or not(self.current_token):
            self.file.write(self._gen_end_tag(name))
            
        elif name == self.TAG_VARIANT:
            all_max_grammeme_sets = self.tokens_max_variant_arrays.get(self.current_token).keys()

            current_grammemes_frozen = frozenset(self.current_grammemes)
            
            #if the current set of grammemes is max, write it down
            if current_grammemes_frozen in all_max_grammeme_sets:
                set_of_current_lex_attrs = self.tokens_max_variant_arrays.get(self.current_token).get(current_grammemes_frozen)
                self._write_grammeme_set(set_of_current_lex_attrs, current_grammemes_frozen)
                self.tokens_max_variant_arrays.get(self.current_token).pop(current_grammemes_frozen, None)
 
        if name == self.TAG_TOKEN and self.current_token:
            self.current_token = None
            
        if name == 'annotation':
            self._close_file()

"""utilities for parsing the dictionary file and exporting grammemes"""
class OpcorpDictionaryGrammemeHandler(opcorp_basic_parsers.OpcorpDictionaryHandler):
    DELIMITER = '\t'
    
    def __init__(self, out_path, encoding):
        super().__init__()
        self.file = None
        self.out_path = out_path
        self.encoding = encoding

        self._new_file()
        self.current_grammeme = ''
        
    def _new_file(self):
        self.file = codecs.open(self.out_path, 'w', self.encoding)

    def _close_file(self):
        self.file.close()

    def startElement(self, name, attrs):
        if name == self.TAG_DICT_GRAMMEME:
            current_parent = attrs.get('parent')
            if current_parent is None or current_parent == "":
                current_parent = "None"
            self.current_grammeme += current_parent

    def endElement(self, name):
        if name == self.TAG_DICT_GRAMMEME:
            self.file.write(self.current_grammeme + os.linesep)
            self.current_grammeme = ''
        elif name == self.TAG_DICT_GRAMMEME_LIST:
            self._close_file()
            raise opcorp_basic_parsers.DictionaryEndParseException("stop parsing")
        

    def characters(self, content):
        if content.strip():
            self.current_grammeme += self.DELIMITER + content.strip()
